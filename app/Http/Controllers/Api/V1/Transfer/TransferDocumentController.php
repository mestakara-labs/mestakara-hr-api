<?php

namespace App\Http\Controllers\Api\V1\Transfer;

use App\Enums\TransferStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Transfer\TransferResource;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Services\FileStorage;
use App\Services\Transfer\TransferDocument;
use App\Services\Transfer\TransferService;
use App\Support\ApiResponse;
use App\Support\UploadRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dokumen usulan mutasi.
 *
 *   POST /transfers/{id}/basis-document               simpan dokumen dasar (Draf / Menunggu Dokumen)
 *   POST /transfers/{id}/items/{itemId}/excerpt       unggah scan petikan SK satu karyawan
 *   GET  /transfers/{id}/documents?type=...           buka / unduh dokumen
 */
class TransferDocumentController extends Controller
{
    public function __construct(private TransferService $transferService) {}

    /**
     * Simpan dokumen dasar
     *
     * Multipart: basis_document_type/number/date, basis_signer_*, file `basis_document_file`
     * (atau `keep_basis_document_file=1` untuk mempertahankan file lama).
     */
    public function saveBasisDocument(Request $request, Transfer $transfer): JsonResponse
    {
        $transfer = $this->transferService->saveBasisDocument($transfer, $request->all(), $request->file('basis_document_file'));

        return ApiResponse::success(TransferResource::make($transfer->load(TransferResource::RELATIONS)), 'Dokumen dasar berhasil disimpan.');
    }

    /**
     * Unggah scan petikan SK
     *
     * Multipart: `excerpt_file`. Kalau usulan sudah berlaku, file dokumen penugasan baru karyawan ini ikut diganti.
     */
    public function uploadExcerpt(Request $request, Transfer $transfer, TransferItem $item): JsonResponse
    {
        $file = $request->file('excerpt_file');
        if ($file === null) {
            return ApiResponse::error('Scan petikan belum diunggah.', 422, ['excerpt_file' => ['Pilih file scan petikan SK']]);
        }
        if ($fileError = UploadRule::document()->check($file)) {
            return ApiResponse::error('Scan petikan belum benar.', 422, ['excerpt_file' => [$fileError]]);
        }

        $transfer = $this->transferService->uploadExcerpt($transfer, $item, $file);

        return ApiResponse::success(TransferResource::make($transfer->load(TransferResource::RELATIONS)), 'Scan petikan SK berhasil diunggah.');
    }

    /**
     * Buka dokumen usulan
     *
     * Mengirim isi file, bukan JSON:
     *   type=sk&format=pdf|doc                  SK kolektif dari template (bukan Draf)
     *   type=petikan&item={id}&format=pdf|doc   petikan SK satu karyawan dari template
     *   type=scan                               scan SK kolektif bertanda tangan
     *   type=scan-petikan&item={id}             scan petikan SK satu karyawan
     *   type=dasar                              dokumen dasar mutasi
     */
    public function show(Request $request, Transfer $transfer, FileStorage $fileStorage, TransferDocument $document): Response
    {
        $type = $request->query('type');
        $itemId = $request->query('item');

        $storedFile = match ($type) {
            'scan' => $transfer->signedFile,
            'scan-petikan' => $transfer->items()->find($itemId)?->excerptFile,
            'dasar' => $transfer->basisDocumentFile,
            default => null,
        };
        if ($storedFile !== null) {
            return $fileStorage->respond($storedFile);
        }

        // Dokumen dari template baru bisa dibuat setelah nomor & tanggal SK diisi (bukan Draf).
        $generated = match (true) {
            $transfer->status === TransferStatus::Draft => null,
            $type === 'sk' => $document->make($transfer, null, (string) $request->query('format', 'pdf')),
            $type === 'petikan' && $itemId !== null => $document->make($transfer, $itemId, (string) $request->query('format', 'pdf')),
            default => null,
        };

        abort_if($generated === null, 404, 'Dokumen tidak ditemukan.');

        $disposition = $generated['mime'] === 'application/pdf' ? 'inline' : 'attachment';

        return response($generated['content'], 200, [
            'Content-Type' => $generated['mime'],
            'Content-Disposition' => "{$disposition}; filename*=UTF-8''".rawurlencode($generated['name']),
        ]);
    }
}
