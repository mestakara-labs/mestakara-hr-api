<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceImportType;
use App\Enums\AttendanceSource;
use App\Enums\AttendanceWriteOutcome;
use App\Enums\ImportBatchStatus;
use App\Enums\OvertimeSource;
use App\Enums\OvertimeType;
use App\Models\AttendanceDay;
use App\Models\AttendanceImportBatch;
use App\Models\AttendanceImportConflict;
use App\Models\Holiday;
use App\Models\OvertimeEntry;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * UNGGAH ABSENSI per jenis (daftar hadir, lembur reguler, lembur DW):
 *
 *   template()  file .xlsx berisi data tersimpan periode itu
 *   preview()   baca & periksa file, TIDAK menyimpan
 *   store()     simpan kalau tidak ada baris salah (422 kalau ada; 423 kalau periode dikunci):
 *               batch lama jenis itu -> "diganti", batch baru -> "aktif", lalu
 *               daftar hadir: setiap tanggal ditulis / dikosongkan lewat AttendanceDayService (sumber import_excel)
 *               lembur:       semua lembur jenis itu bersumber import_excel di periode itu diganti isi file
 *               konflik dengan data mobile / manual disimpan di attendance_import_conflicts
 */
class AttendanceImportService
{
    public function __construct(
        private AttendanceEmployees $attendanceEmployees,
        private AttendanceListImportReader $listReader,
        private AttendanceListImportChecker $listChecker,
        private OvertimeImportReader $overtimeReader,
        private OvertimeImportChecker $overtimeChecker,
        private AttendanceListTemplateWorkbook $listWorkbook,
        private OvertimeTemplateWorkbook $overtimeWorkbook,
        private AttendanceDayService $dayService,
    ) {}

    // -------------------------------------------------------------------------
    // Template
    // -------------------------------------------------------------------------

    /** Tulis template ke file sementara; hasilnya path file. */
    public function template(AttendancePeriod $period, AttendanceImportType $type): string
    {
        $employees = $this->attendanceEmployees->forPeriod($period);

        if ($type === AttendanceImportType::AttendanceList) {
            $codes = [];
            $this->daysOf($period)->each(function (AttendanceDay $day) use (&$codes) {
                $codes[$day->employee_id][$day->date->toDateString()] = $day->status_code->value;
            });
            $holidays = Holiday::query()
                ->where('date', '>=', $period->start()->toDateString())
                ->where('date', '<', $period->end()->addDay()->toDateString())
                ->get()
                ->mapWithKeys(fn (Holiday $holiday) => [$holiday->date->toDateString() => $holiday->name])
                ->all();

            return $this->listWorkbook->write($period, $employees, $codes, $holidays);
        }

        $entries = $this->overtimeOf($period, OvertimeImportChecker::overtimeType($type))->load('employee')
            ->sortBy(fn (OvertimeEntry $entry) => [$entry->date->toDateString(), $entry->employee->name, $entry->start_time])
            ->values();

        return $this->overtimeWorkbook->write($period, $type, $employees, $entries);
    }

    /**
     * Jumlah data tersimpan per jenis pada periode itu, dari SEMUA sumber:
     * daftar_hadir = jumlah karyawan yang punya kode; lembur = jumlah baris lembur jenis itu.
     *
     * @return array<string, int> [jenis unggahan => jumlah]
     */
    public function entryCounts(AttendancePeriod $period): array
    {
        $from = $period->start()->toDateString();
        $to = $period->end()->addDay()->toDateString();
        $overtime = OvertimeEntry::query()
            ->where('date', '>=', $from)
            ->where('date', '<', $to)
            ->selectRaw('overtime_type, count(*) as total')
            ->groupBy('overtime_type')
            ->toBase()
            ->pluck('total', 'overtime_type');

        return [
            AttendanceImportType::AttendanceList->value => AttendanceDay::query()->where('date', '>=', $from)->where('date', '<', $to)->distinct()->count('employee_id'),
            AttendanceImportType::RegularOvertime->value => (int) ($overtime[OvertimeType::Regular->value] ?? 0),
            AttendanceImportType::DailyWorkerOvertime->value => (int) ($overtime[OvertimeType::DailyWorker->value] ?? 0),
        ];
    }

    /** Mis. "Template Daftar Hadir - Oktober 2026.xlsx". */
    public function templateFileName(AttendancePeriod $period, AttendanceImportType $type): string
    {
        return 'Template '.ImportWorkbook::typeLabel($type).' - '.$period->label().'.xlsx';
    }

    // -------------------------------------------------------------------------
    // Pratinjau & simpan
    // -------------------------------------------------------------------------

    /** Hasil pemeriksaan untuk front end: { summary, rows }. */
    public function preview(AttendancePeriod $period, AttendanceImportType $type, UploadedFile $file): array
    {
        $period->ensureNotLocked();
        $result = $this->check($period, $type, $file);

        return ['summary' => $result['summary'], 'rows' => $result['rows']];
    }

    /**
     * Simpan unggahan. Hasilnya batch baru + ringkasan { created, updated, deleted, unchanged, conflicts }.
     *
     * @return array{batch: AttendanceImportBatch, summary: array<string, int>}
     */
    public function store(AttendancePeriod $period, AttendanceImportType $type, UploadedFile $file): array
    {
        $period->ensureNotLocked();
        $result = $this->check($period, $type, $file);
        $this->rejectInvalidRows($result['rows']);

        return DB::transaction(function () use ($period, $type, $file, $result) {
            AttendanceImportBatch::query()
                ->where('period', $period->period)
                ->where('import_type', $type)
                ->where('status', ImportBatchStatus::Active)
                ->update(['status' => ImportBatchStatus::Replaced]);

            $batch = AttendanceImportBatch::create([
                'period' => $period->period,
                'import_type' => $type,
                'file_name' => $file->getClientOriginalName(),
                'uploaded_by' => auth()->id(),
                'total_rows' => $result['summary']['total_rows'],
                'status' => ImportBatchStatus::Active,
            ]);

            $counts = $type === AttendanceImportType::AttendanceList
                ? $this->applyAttendanceList($batch, $result)
                : $this->applyOvertime($batch, $period, $type, $result);

            foreach ($result['conflicts'] as $conflict) {
                AttendanceImportConflict::create([
                    'import_batch_id' => $batch->id,
                    'employee_id' => $conflict['employee_id'],
                    'date' => $conflict['date'],
                    'current_value' => $conflict['current_code'],
                    'current_source' => $conflict['current_source'],
                    'file_value' => $conflict['file_code'],
                ]);
            }

            $batch->update([
                'created_count' => $counts['created'],
                'updated_count' => $counts['updated'],
                'deleted_count' => $counts['deleted'],
                'skipped_count' => $counts['unchanged'],
                'conflict_count' => count($result['conflicts']) + $counts['conflicts'],
            ]);

            return [
                'batch' => $batch->fresh('uploader'),
                'summary' => [...$counts, 'conflicts' => $batch->conflict_count],
            ];
        });
    }

    private function check(AttendancePeriod $period, AttendanceImportType $type, UploadedFile $file): array
    {
        return $type === AttendanceImportType::AttendanceList
            ? $this->listChecker->check($period, $this->listReader->rows($file, $period))
            : $this->overtimeChecker->check($period, $type, $this->overtimeReader->rows($file, $period, $type));
    }

    /** Tolak simpan (422) kalau masih ada baris salah: errors["rows.<baris>"] = ["Kolom: pesan", …]. */
    private function rejectInvalidRows(array $rows): void
    {
        $errors = [];
        foreach ($rows as $row) {
            if ($row['status'] === 'salah') {
                $errors['rows.'.$row['row']] = array_map(fn (array $error) => "{$error['column']}: {$error['message']}", $row['errors']);
            }
        }

        if ($errors !== []) {
            throw new HttpResponseException(ApiResponse::error(
                'Masih ada '.count($errors).' baris yang salah. Perbaiki file lalu unggah kembali.',
                422,
                $errors,
            ));
        }
    }

    /** @return array{created: int, updated: int, deleted: int, unchanged: int, conflicts: int} */
    private function applyAttendanceList(AttendanceImportBatch $batch, array $result): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => $result['unchanged'], 'conflicts' => 0];

        $items = array_map(fn (array $change) => [
            'employee_id' => $change['employee_id'],
            'date' => $change['date'],
            'values' => $change['code'] === null ? null : ['status_code' => $change['code']],
        ], $result['changes']);

        foreach ($this->dayService->writeMany($items, AttendanceSource::ImportExcel, null, $batch) as $outcome) {
            match ($outcome) {
                AttendanceWriteOutcome::Created => $counts['created']++,
                AttendanceWriteOutcome::Updated => $counts['updated']++,
                AttendanceWriteOutcome::Deleted => $counts['deleted']++,
                AttendanceWriteOutcome::Conflict => $counts['conflicts']++,
                default => $counts['unchanged']++,
            };
        }

        return $counts;
    }

    /** @return array{created: int, updated: int, deleted: int, unchanged: int, conflicts: int} */
    private function applyOvertime(AttendanceImportBatch $batch, AttendancePeriod $period, AttendanceImportType $type, array $result): array
    {
        $overtimeType = OvertimeImportChecker::overtimeType($type);

        // Lembur jenis ini dari Excel sebelumnya (dan untuk Lembur DW: juga rekomendasi sistem) diganti seluruhnya
        // untuk SEMUA karyawan, termasuk yang tidak ada di file. Manual / mobile tidak disentuh.
        $replacedSources = OvertimeImportChecker::replacedSources($overtimeType);
        $deleted = $this->overtimeOf($period, $overtimeType)
            ->filter(fn (OvertimeEntry $entry) => in_array($entry->source, $replacedSources, true))
            ->each(fn (OvertimeEntry $entry) => $entry->delete())
            ->count();

        foreach ($result['entries'] as $entry) {
            OvertimeEntry::create([
                ...$entry,
                'duration_minutes' => OvertimeRules::minutes($entry['start_time'], $entry['end_time']),
                'overtime_type' => $overtimeType,
                'source' => OvertimeSource::ImportExcel,
                'import_batch_id' => $batch->id,
                'created_by' => auth()->id(),
            ]);
        }

        return ['created' => count($result['entries']), 'updated' => 0, 'deleted' => $deleted, 'unchanged' => 0, 'conflicts' => 0];
    }

    /** @return Collection<int, AttendanceDay> */
    private function daysOf(AttendancePeriod $period): Collection
    {
        return AttendanceDay::query()
            ->where('date', '>=', $period->start()->toDateString())
            ->where('date', '<', $period->end()->addDay()->toDateString())
            ->get();
    }

    /** @return Collection<int, OvertimeEntry> */
    private function overtimeOf(AttendancePeriod $period, OvertimeType $type): Collection
    {
        return OvertimeEntry::query()
            ->where('overtime_type', $type)
            ->where('date', '>=', $period->start()->toDateString())
            ->where('date', '<', $period->end()->addDay()->toDateString())
            ->get();
    }
}
