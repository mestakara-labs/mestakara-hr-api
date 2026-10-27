<?php

namespace App\Http\Controllers\Api\V1\Termination;

use App\Http\Controllers\Controller;
use App\Http\Requests\Termination\TerminationRequest;
use App\Http\Resources\Termination\TerminationResource;
use App\Models\EmployeeTermination;
use App\Services\FileStorage;
use App\Services\Termination\TerminationImpact;
use App\Services\Termination\TerminationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Usulan Penonaktifan Karyawan.
 *
 *   GET    /employee-terminations                   daftar usulan (terbaru di atas)
 *   POST   /employee-terminations                   buat usulan (multipart, lihat ProposalRequest)
 *   POST   /employee-terminations/preview           Pratinjau Dampak (tanpa menyimpan)
 *   GET    /employee-terminations/{id}              detail usulan (+ dampak per karyawan)
 *   PUT    /employee-terminations/{id}              ubah usulan Draf (multipart)
 *   DELETE /employee-terminations/{id}              hapus usulan Draf
 *   POST   /employee-terminations/{id}/documents    ganti dokumen usulan Berlaku
 *   POST   /employee-terminations/{id}/cancel       batalkan usulan Berlaku (alasan wajib)
 *   GET    /employee-terminations/{id}/files        buka file dokumen (?item= untuk per orang)
 */
class EmployeeTerminationController extends Controller
{
    public function __construct(private TerminationService $terminationService) {}

    /**
     * Daftar usulan penonaktifan
     */
    public function index(): JsonResponse
    {
        $terminations = EmployeeTermination::query()
            ->with(TerminationResource::RELATIONS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(TerminationResource::collection($terminations), 'Daftar usulan penonaktifan karyawan.');
    }

    /**
     * Buat usulan penonaktifan
     *
     * Multipart. `action=draft` = Simpan sebagai Draf; `action=submit` = Simpan & Berlakukan (dokumen WAJIB).
     */
    public function store(TerminationRequest $request): JsonResponse
    {
        $termination = $this->terminationService->save(new EmployeeTermination, $request);

        return ApiResponse::success($this->resource($termination), $this->terminationService->saveMessage($termination), 201);
    }

    /**
     * Pratinjau dampak
     *
     * Body JSON: `{ items: [{ id, employee_id, effective_date }] }`. Hasilnya dampak per id item.
     */
    public function preview(Request $request, TerminationImpact $impact): JsonResponse
    {
        $items = collect($request->input('items', []))
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item) => [
                'id' => (string) ($item['id'] ?? ''),
                'employee_id' => (string) ($item['employee_id'] ?? ''),
                'effective_date' => (string) ($item['effective_date'] ?? ''),
            ])
            ->values()
            ->all();

        return ApiResponse::success((object) $impact->planned($items), 'Pratinjau dampak penonaktifan.');
    }

    /**
     * Detail usulan penonaktifan
     */
    public function show(EmployeeTermination $employeeTermination): JsonResponse
    {
        return ApiResponse::success($this->resource($employeeTermination), 'Detail usulan penonaktifan.');
    }

    /**
     * Ubah usulan penonaktifan
     *
     * Hanya usulan Draf (usulan lain ditolak 409 oleh TerminationRequest).
     */
    public function update(TerminationRequest $request, EmployeeTermination $employeeTermination): JsonResponse
    {
        $termination = $this->terminationService->save($employeeTermination, $request);

        return ApiResponse::success($this->resource($termination), $this->terminationService->saveMessage($termination));
    }

    /**
     * Hapus usulan Draf
     */
    public function destroy(EmployeeTermination $employeeTermination): JsonResponse
    {
        $this->terminationService->delete($employeeTermination);

        return ApiResponse::success(null, 'Draf usulan penonaktifan berhasil dihapus.');
    }

    /**
     * Ganti dokumen
     *
     * Multipart: `item_id` kosong = dokumen kolektif, diisi = per orang; document_*, signer_*,
     * file `document_file` (atau `keep_document_file=1`). Hanya usulan Berlaku.
     */
    public function saveDocument(Request $request, EmployeeTermination $employeeTermination): JsonResponse
    {
        $termination = $this->terminationService->saveDocument(
            $employeeTermination,
            $request->input('item_id') ?: null,
            $request->all(),
            $request->file('document_file'),
        );

        return ApiResponse::success($this->resource($termination), 'Dokumen berhasil disimpan.');
    }

    /**
     * Batalkan usulan penonaktifan
     *
     * Body JSON: `{ reason }` (wajib). Jabatan & atasan bawahan dikembalikan, karyawan Aktif lagi.
     */
    public function cancel(Request $request, EmployeeTermination $employeeTermination): JsonResponse
    {
        $termination = $this->terminationService->cancel($employeeTermination, $request->input('reason'));

        return ApiResponse::success($this->resource($termination), 'Usulan penonaktifan berhasil dibatalkan. Karyawannya kembali Aktif.');
    }

    /**
     * Buka file dokumen
     *
     * `?item={id}` untuk dokumen per orang; tanpa item = dokumen kolektif. Mengirim isi file.
     */
    public function file(Request $request, EmployeeTermination $employeeTermination, FileStorage $fileStorage): StreamedResponse
    {
        $itemId = $request->query('item');
        $target = $itemId ? $employeeTermination->items()->find($itemId) : $employeeTermination;

        abort_if($target?->documentFile === null, 404, 'File tidak ditemukan.');

        return $fileStorage->respond($target->documentFile);
    }

    private function resource(EmployeeTermination $termination): TerminationResource
    {
        return TerminationResource::make($termination->load(TerminationResource::RELATIONS));
    }
}
