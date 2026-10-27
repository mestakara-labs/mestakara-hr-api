<?php

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\OvertimeEntryIndexRequest;
use App\Http\Requests\Attendance\OvertimeEntryRequest;
use App\Models\OvertimeEntry;
use App\Services\Attendance\AttendanceOverview;
use App\Services\Attendance\AttendancePeriod;
use App\Services\Attendance\OvertimeEntryService;
use App\Services\Attendance\OvertimeImportChecker;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Lembur Reguler / Lembur DW per baris.
 *
 *   GET    /overtime-entries?period=YYYY-MM&type=lembur_reguler|lembur_dw   daftar, urut tanggal lalu nama
 *   POST   /overtime-entries          tambah satu baris (sumber manual)
 *   PUT    /overtime-entries/{id}     ubah (baris Excel / rekomendasi yang diubah menjadi manual)
 *   DELETE /overtime-entries/{id}     hapus { reason }
 *
 * Aturan & jejak di App\Services\Attendance\OvertimeEntryService.
 */
class OvertimeEntryController extends Controller
{
    public function __construct(
        private OvertimeEntryService $entryService,
        private AttendanceOverview $overview,
    ) {}

    /**
     * Daftar lembur per jenis
     *
     * `warnings` berisi teks singkat, mis. "Lebih dari 4 jam lembur pada tanggal ini (5 jam)", "Tanggal ini berkode L (Libur) di daftar hadir".
     */
    public function index(OvertimeEntryIndexRequest $request): JsonResponse
    {
        $type = OvertimeImportChecker::overtimeType($request->importType());

        return ApiResponse::success($this->overview->overtimeList($request->period(), $type), 'Daftar lembur.');
    }

    /**
     * Tambah lembur (manual)
     *
     * Tumpang tindih & hari I/S/M/C = 422; melebihi batas jam / hari L-kosong = boleh, terlihat di `warnings`.
     */
    public function store(OvertimeEntryRequest $request): JsonResponse
    {
        $entry = $this->entryService->create(new AttendancePeriod($request->validated('period')), $request->validated());

        return ApiResponse::success($this->row($entry), 'Lembur berhasil ditambahkan.', 201);
    }

    /**
     * Ubah lembur (menjadi manual)
     */
    public function update(OvertimeEntryRequest $request, OvertimeEntry $overtimeEntry): JsonResponse
    {
        $entry = $this->entryService->update($overtimeEntry, $request->validated());

        return ApiResponse::success($this->row($entry), 'Lembur berhasil diubah.');
    }

    /**
     * Hapus lembur
     */
    public function destroy(OvertimeEntryRequest $request, OvertimeEntry $overtimeEntry): JsonResponse
    {
        $this->entryService->delete($overtimeEntry, $request->validated('reason'));

        return ApiResponse::success(null, 'Lembur berhasil dihapus.');
    }

    /** Baris lembur dalam format yang sama dengan GET /overtime-entries (termasuk peringatan). */
    private function row(OvertimeEntry $entry): ?array
    {
        $rows = $this->overview->overtimeList(new AttendancePeriod($entry->date->format('Y-m')), $entry->overtime_type);

        return collect($rows)->firstWhere('id', $entry->id);
    }
}
