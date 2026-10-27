<?php

namespace App\Http\Controllers\Api\V1\Transfer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transfer\TransferRequest;
use App\Http\Resources\Transfer\TransferResource;
use App\Models\Transfer;
use App\Services\Transfer\TransferService;
use App\Services\Transfer\TransferSupervisorChanges;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Usulan Mutasi & Penempatan.
 *
 *   GET    /transfers            daftar usulan (terbaru di atas)
 *   POST   /transfers            buat usulan (multipart, lihat TransferRequest)
 *   POST   /transfers/preview    perkiraan perubahan atasan untuk isian yang belum disimpan
 *   GET    /transfers/{id}       detail usulan
 *   PUT    /transfers/{id}       ubah usulan Draf / Menunggu Dokumen (multipart)
 *   DELETE /transfers/{id}       hapus usulan Draf
 */
class TransferController extends Controller
{
    public function __construct(private TransferService $transferService) {}

    /**
     * Daftar usulan mutasi
     */
    public function index(): JsonResponse
    {
        $transfers = Transfer::query()->with(TransferResource::RELATIONS)->orderByDesc('created_at')->orderByDesc('id')->get();

        return ApiResponse::success(TransferResource::collection($transfers), 'Daftar usulan mutasi.');
    }

    /**
     * Buat usulan mutasi
     *
     * Multipart. `action=draft` = simpan sebagai Draf; `action=submit` = simpan & lanjutkan
     * (jalur Template -> Menunggu Dokumen; jalur Unggah + signed_file -> langsung Berlaku).
     * Error per karyawan memakai nama isian `items.<id item>.<isian>`.
     */
    public function store(TransferRequest $request): JsonResponse
    {
        $transfer = $this->transferService->save(new Transfer, $request);

        return ApiResponse::success($this->resource($transfer), $this->transferService->saveMessage($transfer), 201);
    }

    /**
     * Perkiraan perubahan atasan
     *
     * Body JSON: `{ items: [{ id, employee_id, from_assignment_id, position_id, restore_subordinates }] }`.
     * Tidak menyimpan apa pun.
     */
    public function preview(Request $request, TransferSupervisorChanges $supervisorChanges): JsonResponse
    {
        $items = collect($request->input('items', []))
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item) => [
                'id' => (string) ($item['id'] ?? ''),
                'employee_id' => (string) ($item['employee_id'] ?? ''),
                'from_assignment_id' => (string) ($item['from_assignment_id'] ?? ''),
                'position_id' => (string) ($item['position_id'] ?? ''),
                'restore_subordinates' => filter_var($item['restore_subordinates'] ?? false, FILTER_VALIDATE_BOOL),
            ])
            ->values()
            ->all();

        return ApiResponse::success($supervisorChanges->preview($items), 'Perkiraan perubahan atasan.');
    }

    /**
     * Detail usulan mutasi
     */
    public function show(Transfer $transfer): JsonResponse
    {
        return ApiResponse::success($this->resource($transfer), 'Detail usulan mutasi.');
    }

    /**
     * Ubah usulan mutasi
     *
     * Hanya usulan Draf & Menunggu Dokumen. Isian sama dengan buat usulan;
     * file lama dipertahankan dengan `keep_signed_file=1` / `keep_basis_document_file=1`.
     */
    public function update(TransferRequest $request, Transfer $transfer): JsonResponse
    {
        $transfer = $this->transferService->save($transfer, $request);

        return ApiResponse::success($this->resource($transfer), $this->transferService->saveMessage($transfer));
    }

    /**
     * Hapus usulan Draf
     */
    public function destroy(Transfer $transfer): JsonResponse
    {
        $this->transferService->delete($transfer);

        return ApiResponse::success(null, 'Draf usulan mutasi berhasil dihapus.');
    }

    private function resource(Transfer $transfer): TransferResource
    {
        return TransferResource::make($transfer->load(TransferResource::RELATIONS));
    }
}
