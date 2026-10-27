<?php

namespace App\Http\Controllers\Api\V1\Activation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Activation\ActivationRequest;
use App\Http\Resources\Activation\ActivationResource;
use App\Models\EmployeeActivation;
use App\Services\Activation\ActivationService;
use App\Services\FileStorage;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Usulan Penambahan Karyawan.
 *
 *   GET    /employee-activations                   daftar usulan (terbaru di atas)
 *   POST   /employee-activations                   buat usulan (multipart, lihat ProposalRequest)
 *   GET    /employee-activations/{id}              detail usulan
 *   PUT    /employee-activations/{id}              ubah usulan Draf (multipart)
 *   DELETE /employee-activations/{id}              hapus usulan Draf
 *   POST   /employee-activations/{id}/documents    unggah / ganti dokumen usulan Berlaku
 *   POST   /employee-activations/{id}/cancel       batalkan usulan Berlaku (alasan wajib)
 *   GET    /employee-activations/{id}/files        buka file dokumen (?item= untuk per orang)
 */
class EmployeeActivationController extends Controller
{
    public function __construct(private ActivationService $activationService) {}

    /**
     * Daftar usulan penambahan
     */
    public function index(): JsonResponse
    {
        $activations = EmployeeActivation::query()
            ->with(ActivationResource::RELATIONS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(ActivationResource::collection($activations), 'Daftar usulan penambahan karyawan.');
    }

    /**
     * Buat usulan penambahan
     *
     * Multipart. `action=draft` = Simpan sebagai Draf; `action=submit` = Simpan & Berlakukan.
     * Error per karyawan memakai nama isian `items.<id item>.<isian>`.
     */
    public function store(ActivationRequest $request): JsonResponse
    {
        $activation = $this->activationService->save(new EmployeeActivation, $request);

        return ApiResponse::success($this->resource($activation), $this->activationService->saveMessage($activation), 201);
    }

    /**
     * Detail usulan penambahan
     */
    public function show(EmployeeActivation $employeeActivation): JsonResponse
    {
        return ApiResponse::success($this->resource($employeeActivation), 'Detail usulan penambahan.');
    }

    /**
     * Ubah usulan penambahan
     *
     * Hanya usulan Draf (usulan lain ditolak 409 oleh ActivationRequest).
     */
    public function update(ActivationRequest $request, EmployeeActivation $employeeActivation): JsonResponse
    {
        $activation = $this->activationService->save($employeeActivation, $request);

        return ApiResponse::success($this->resource($activation), $this->activationService->saveMessage($activation));
    }

    /**
     * Hapus usulan Draf
     */
    public function destroy(EmployeeActivation $employeeActivation): JsonResponse
    {
        $this->activationService->delete($employeeActivation);

        return ApiResponse::success(null, 'Draf usulan penambahan berhasil dihapus. Draf karyawannya tetap tersimpan (Belum Aktif).');
    }

    /**
     * Unggah / ganti dokumen
     *
     * Multipart: `item_id` kosong = dokumen kolektif, diisi = per orang; document_*, signer_*,
     * file `document_file` (atau `keep_document_file=1`). Hanya usulan Berlaku.
     */
    public function saveDocument(Request $request, EmployeeActivation $employeeActivation): JsonResponse
    {
        $activation = $this->activationService->saveDocument(
            $employeeActivation,
            $request->input('item_id') ?: null,
            $request->all(),
            $request->file('document_file'),
        );

        return ApiResponse::success($this->resource($activation), 'Dokumen berhasil disimpan.');
    }

    /**
     * Batalkan usulan penambahan
     *
     * Body JSON: `{ reason }` (wajib). Penugasan yang dibuat usulan ini dihapus.
     */
    public function cancel(Request $request, EmployeeActivation $employeeActivation): JsonResponse
    {
        $activation = $this->activationService->cancel($employeeActivation, $request->input('reason'));

        return ApiResponse::success($this->resource($activation), 'Usulan penambahan berhasil dibatalkan.');
    }

    /**
     * Buka file dokumen
     *
     * `?item={id}` untuk dokumen per orang; tanpa item = dokumen kolektif. Mengirim isi file.
     */
    public function file(Request $request, EmployeeActivation $employeeActivation, FileStorage $fileStorage): StreamedResponse
    {
        $itemId = $request->query('item');
        $target = $itemId ? $employeeActivation->items()->find($itemId) : $employeeActivation;

        abort_if($target?->documentFile === null, 404, 'File tidak ditemukan.');

        return $fileStorage->respond($target->documentFile);
    }

    private function resource(EmployeeActivation $activation): ActivationResource
    {
        return ActivationResource::make($activation->load(ActivationResource::RELATIONS));
    }
}
