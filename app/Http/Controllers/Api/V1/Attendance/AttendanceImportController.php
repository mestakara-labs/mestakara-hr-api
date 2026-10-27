<?php

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Enums\AttendanceImportType;
use App\Enums\ImportBatchStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendanceImportRequest;
use App\Http\Requests\Attendance\AttendancePeriodRequest;
use App\Http\Requests\Attendance\AttendanceTemplateRequest;
use App\Models\AttendanceImportBatch;
use App\Services\Attendance\AttendanceImportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Unggah Absensi (3 tab: Daftar Hadir, Lembur Reguler, Lembur DW).
 *
 *   GET  /attendance-imports?period=           status periode + riwayat unggahan per jenis
 *   GET  /attendance-imports/template?period=&type=   file .xlsx (bukan JSON)
 *   POST /attendance-imports/preview           periksa file, TIDAK menyimpan
 *   POST /attendance-imports                   simpan (422 kalau ada baris salah, 423 kalau periode dikunci)
 *
 * Alurnya ada di App\Services\Attendance\AttendanceImportService.
 */
class AttendanceImportController extends Controller
{
    public function __construct(private AttendanceImportService $importService) {}

    /**
     * Riwayat unggahan per periode
     *
     * `sections` selalu 3, urut: daftar_hadir, lembur_reguler, lembur_dw. `batches` terbaru dulu.
     */
    public function index(AttendancePeriodRequest $request): JsonResponse
    {
        $period = $request->period();
        $batches = AttendanceImportBatch::query()
            ->with('uploader:id,name')
            ->where('period', $period->period)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (AttendanceImportBatch $batch) => $batch->import_type->value);
        $entryCounts = $this->importService->entryCounts($period);

        return ApiResponse::success([
            'period' => $period->period,
            'period_status' => $period->status()->value,
            'sections' => array_map(fn (AttendanceImportType $type) => [
                'import_type' => $type->value,
                /** Jumlah data SEMUA sumber: daftar hadir = karyawan yang punya kode; lembur = baris lembur. */
                'entry_count' => $entryCounts[$type->value],
                'active_batch' => ($active = ($batches[$type->value] ?? collect())->first(fn (AttendanceImportBatch $batch) => $batch->status === ImportBatchStatus::Active))
                    ? self::batch($active) : null,
                'batches' => ($batches[$type->value] ?? collect())->map(fn (AttendanceImportBatch $batch) => self::batch($batch))->values(),
            ], AttendanceImportType::cases()),
        ], 'Riwayat unggahan absensi.');
    }

    /**
     * Unduh template unggah absensi (Excel)
     *
     * Mengirim file .xlsx berisi data yang tersimpan untuk periode itu. Nama file di header Content-Disposition.
     */
    public function template(AttendanceTemplateRequest $request): BinaryFileResponse
    {
        $period = $request->period();
        $type = $request->importType();
        $path = $this->importService->template($period, $type);
        $fileName = $this->importService->templateFileName($period, $type);

        return response()
            ->file($path, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$fileName.'"; filename*=UTF-8\'\''.rawurlencode($fileName),
            ])
            ->deleteFileAfterSend();
    }

    /**
     * Pratinjau unggahan
     *
     * `rows[].status`: benar / peringatan / salah. Baris SALAH memblokir simpan; PERINGATAN & KONFLIK tidak.
     */
    public function preview(AttendanceImportRequest $request): JsonResponse
    {
        $result = $this->importService->preview($request->period(), $request->importType(), $request->file('file'));

        return ApiResponse::success($result, 'Pratinjau unggahan.');
    }

    /**
     * Simpan unggahan
     *
     * Menggantikan data hasil unggahan sebelumnya jenis itu pada periode itu. Data mobile / manual tidak ditimpa.
     */
    public function store(AttendanceImportRequest $request): JsonResponse
    {
        $result = $this->importService->store($request->period(), $request->importType(), $request->file('file'));

        return ApiResponse::success([
            'batch' => self::batch($result['batch']),
            'summary' => $result['summary'],
        ], 'Unggahan berhasil disimpan.', 201);
    }

    /** Satu batch unggahan untuk front end. */
    public static function batch(AttendanceImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'import_type' => $batch->import_type->value,
            'status' => $batch->status->value,
            'file_name' => $batch->file_name,
            'uploaded_by' => $batch->uploader?->name,
            'created_at' => $batch->created_at?->toIso8601String(),
            'total_rows' => $batch->total_rows,
            'created_count' => $batch->created_count,
            'updated_count' => $batch->updated_count,
            'deleted_count' => $batch->deleted_count,
            'skipped_count' => $batch->skipped_count,
            'conflict_count' => $batch->conflict_count,
        ];
    }
}
