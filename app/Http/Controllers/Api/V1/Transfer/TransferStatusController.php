<?php

namespace App\Http\Controllers\Api\V1\Transfer;

use App\Enums\TransferStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Transfer\TransferResource;
use App\Models\Transfer;
use App\Services\Transfer\TransferRules;
use App\Services\Transfer\TransferService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mengubah status usulan mutasi.
 *
 *   POST /transfers/{id}/activate   unggah scan SK bertanda tangan -> Berlaku (dari Menunggu Dokumen)
 *   POST /transfers/{id}/cancel     batalkan usulan (Berlaku: alasan wajib & semua perubahan dikembalikan)
 */
class TransferStatusController extends Controller
{
    public function __construct(private TransferService $transferService) {}

    /**
     * Berlakukan usulan
     *
     * Multipart: `signed_file` = scan SK kolektif yang sudah ditandatangani.
     * Isian usulan diperiksa ulang, karena data bisa berubah sejak usulan disimpan.
     */
    public function activate(Request $request, Transfer $transfer, TransferRules $rules): JsonResponse
    {
        abort_unless(
            $transfer->status === TransferStatus::WaitingDocument,
            409,
            "Usulan berstatus {$transfer->status->value} tidak bisa diaktifkan.",
        );

        $signedFile = $request->file('signed_file');
        if ($signedFile === null) {
            return ApiResponse::error('Scan SK belum diunggah.', 422, ['signed_file' => ['Unggah scan SK yang sudah ditandatangani']]);
        }

        $transfer->load(['items.placementObjects', 'items.supervisors']);
        $errors = $rules->errors($transfer->toPayload(), true, $transfer, $signedFile, $transfer->basis_document_file_id !== null);

        if (isset($errors['signed_file'])) {
            return ApiResponse::error('Scan SK belum benar.', 422, ['signed_file' => $errors['signed_file']]);
        }
        if ($errors !== []) {
            return ApiResponse::error('Ada isian usulan yang sudah tidak sesuai dengan data terbaru. Ubah usulannya terlebih dahulu.', 422, $errors);
        }

        $transfer = $this->transferService->activate($transfer, $signedFile);

        return ApiResponse::success(
            TransferResource::make($transfer->load(TransferResource::RELATIONS)),
            'Scan SK diunggah. Usulan mutasi sekarang berlaku.',
        );
    }

    /**
     * Batalkan usulan
     *
     * Body JSON: `{ reason }`. Alasan wajib untuk usulan Berlaku.
     * Ditolak (409) kalau perubahan dari usulan ini sudah dipakai usulan lain yang lebih baru.
     */
    public function cancel(Request $request, Transfer $transfer): JsonResponse
    {
        $transfer = $this->transferService->cancel($transfer, $request->input('reason'));

        return ApiResponse::success(
            TransferResource::make($transfer->load(TransferResource::RELATIONS)),
            'Usulan mutasi berhasil dibatalkan.',
        );
    }
}
