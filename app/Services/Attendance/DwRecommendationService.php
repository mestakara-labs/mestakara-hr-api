<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceImportType;
use App\Enums\ImportBatchStatus;
use App\Enums\OvertimeChangeAction;
use App\Enums\OvertimeSource;
use App\Enums\OvertimeType;
use App\Models\AttendanceImportBatch;
use App\Models\Employee;
use App\Models\OvertimeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REKOMENDASI LEMBUR DW (Tahap 3):
 *
 *   preview()  hitung rekomendasi per karyawan, TIDAK menyimpan (DwRecommender, acak setiap kali)
 *   store()    validasi ulang hasil (tanggal kandidat, tumpang tindih, batas harian & mingguan), lalu dalam satu
 *              transaksi per karyawan: hapus Lembur DW bersumber import_excel & rekomendasi_sistem miliknya di
 *              periode itu, simpan yang baru (sumber rekomendasi_sistem), catat jejak. Batch Excel Lembur DW aktif
 *              yang semua barisnya sudah terhapus menjadi "diganti".
 *
 * Lembur DW manual & mobile tidak pernah dihapus: upahnya dihitung sebagai titik awal, tanggalnya tidak dipakai.
 * Periode dikunci -> 422.
 */
class DwRecommendationService
{
    public const SKIP_NO_SCHEME = 'Skema upah belum diatur';

    public const SKIP_NO_DW_DAYS = 'Tidak ada hari DW di daftar hadir';

    public const SKIP_NOT_COUNTED = 'Bukan karyawan Casual / Daily Worker yang aktif dalam periode ini';

    public const REPLACED_REASON = 'Diganti rekomendasi sistem';

    public function __construct(
        private AttendanceEmployees $attendanceEmployees,
        private DwRecommender $recommender,
    ) {}

    /**
     * @param  list<string>|null  $employeeIds  null / kosong = semua karyawan dengan hari DW > 0
     */
    public function preview(AttendancePeriod $period, ?array $employeeIds): array
    {
        $this->ensureNotLocked($period);
        $data = DwPeriodData::load($period, $this->attendanceEmployees);
        $pay = DwPay::make();

        $ids = $employeeIds ?: $data->employees->keys()->filter(fn (string $id) => $data->dwDays($id) > 0)->values()->all();
        $names = Employee::query()->whereKey($ids)->get(['id', 'registration_number', 'name'])->keyBy('id');

        $rows = array_map(fn (string $id) => $this->previewEmployee($data, $pay, $id, $names->get($id)), $ids);
        $counted = array_filter($rows, fn (array $row) => $row['skipped_reason'] === null);

        return [
            'summary' => [
                'employees' => count($counted),
                'skipped' => count($rows) - count($counted),
                'target_amount' => array_sum(array_column($counted, 'target_amount')),
                'result_pay' => array_sum(array_column($counted, 'result_pay')),
                'difference' => array_sum(array_column($counted, 'difference')),
                'replaces_entries' => array_sum(array_column($counted, 'replaces_entries')),
            ],
            'employees' => $rows,
        ];
    }

    /**
     * @param  list<array{employee_id: string, entries: list<array{date: string, start_time: string, end_time: string}>}>  $employees
     * @return array{saved_employees: int, created_count: int, deleted_count: int}
     */
    public function store(AttendancePeriod $period, array $employees): array
    {
        $this->ensureNotLocked($period);
        $pay = DwPay::make();
        $description = $pay->settings->dw_default_description;
        if (blank($description)) {
            throw ValidationException::withMessages(['period' => 'Uraian Pekerjaan bawaan Lembur DW belum diisi di Pengaturan Lembur']);
        }

        $data = DwPeriodData::load($period, $this->attendanceEmployees);
        $this->validateSave($data, $pay, $employees);

        return DB::transaction(function () use ($data, $employees, $description) {
            $created = 0;
            $deleted = 0;

            foreach ($employees as $employee) {
                $replaceable = OvertimeEntry::query()
                    ->whereKey(array_column($data->replaceableDwEntries($employee['employee_id']), 'id'))
                    ->get();
                foreach ($replaceable as $entry) {
                    OvertimeTrail::record($entry, OvertimeChangeAction::Deleted, OvertimeSource::SystemRecommendation, self::REPLACED_REASON, OvertimeTrail::values($entry), []);
                    $entry->delete();
                    $deleted++;
                }

                foreach ($employee['entries'] as $item) {
                    $entry = OvertimeEntry::create([
                        'employee_id' => $employee['employee_id'],
                        'date' => $item['date'],
                        'start_time' => $item['start_time'],
                        'end_time' => $item['end_time'],
                        'duration_minutes' => OvertimeRules::minutes($item['start_time'], $item['end_time']),
                        'overtime_type' => OvertimeType::DailyWorker,
                        'description' => $description,
                        'source' => OvertimeSource::SystemRecommendation,
                        'created_by' => auth()->id(),
                    ]);
                    OvertimeTrail::record($entry, OvertimeChangeAction::Recommendation, OvertimeSource::SystemRecommendation, null, [], OvertimeTrail::values($entry));
                    $created++;
                }
            }

            $this->retireEmptyExcelBatches($data->period);

            return ['saved_employees' => count($employees), 'created_count' => $created, 'deleted_count' => $deleted];
        });
    }

    private function previewEmployee(DwPeriodData $data, DwPay $pay, string $id, ?Employee $employee): array
    {
        $item = $data->employees->get($id);
        $row = [
            'employee_id' => $id,
            'registration_number' => $employee?->registration_number,
            'name' => $employee?->name,
            'position' => $item?->positionTitle(),
            'sub_unit' => $item?->subUnit(),
            'dw_days' => $data->dwDays($id),
            'daily_rate' => null,
            'target_amount' => 0,
            'kept_entries' => 0,
            'kept_pay' => 0,
            'replaces_entries' => count($data->replaceableDwEntries($id)),
            'result_pay' => 0,
            'difference' => 0,
            'status' => DwPay::STATUS_NO_SCHEME,
            'skipped_reason' => null,
            'notes' => [],
            'entries' => [],
        ];

        $scheme = $item === null ? null : $data->scheme($item);
        $kept = $data->keptDwEntries($id);
        if ($scheme !== null) {
            $row['daily_rate'] = $scheme->dailyRate();
            $row['target_amount'] = $pay->target($scheme, $row['dw_days']);
            $row['kept_entries'] = count($kept);
            $row['kept_pay'] = $pay->payForEntries($scheme, $kept);
        }

        $row['skipped_reason'] = match (true) {
            $item === null => self::SKIP_NOT_COUNTED,
            $scheme === null => self::SKIP_NO_SCHEME,
            $row['dw_days'] === 0 => self::SKIP_NO_DW_DAYS,
            default => null,
        };
        if ($row['skipped_reason'] !== null) {
            $row['result_pay'] = $row['kept_pay'];
            $row['difference'] = $row['kept_pay'] - $row['target_amount'];
            $row['status'] = $pay->status($scheme, $row['dw_days'], count($kept), $row['kept_pay'], $row['target_amount']);

            return $row;
        }

        $result = $this->recommender->recommend(
            $pay, $scheme, $row['target_amount'], $row['kept_pay'], $data->candidateDates($item), $data->fixedEntries($id),
        );
        $start = substr($pay->settings->dw_default_start_time, 0, 5);
        foreach ($result['minutes'] as $date => $minutes) {
            $end = self::addMinutes($start, $minutes);
            $row['entries'][] = [
                'date' => $date,
                'start_time' => $start,
                'end_time' => $end,
                'duration_minutes' => $minutes,
                'crosses_midnight' => OvertimeRules::crossesMidnight($start, $end),
                'pay' => $pay->payForMinutes($scheme, $minutes),
            ];
        }

        $row['notes'] = $result['notes'];
        $row['result_pay'] = $row['kept_pay'] + array_sum(array_column($row['entries'], 'pay'));
        $row['difference'] = $row['result_pay'] - $row['target_amount'];
        $row['status'] = $pay->status($scheme, $row['dw_days'], count($kept) + count($row['entries']), $row['result_pay'], $row['target_amount']);

        return $row;
    }

    /**
     * Validasi ulang hasil sebelum simpan. Error per karyawan / baris:
     *   employees.<i>                   karyawan tidak dihitung / ganda
     *   employees.<i>.entries.<j>       tanggal bukan kandidat, durasi 0, tumpang tindih, batas harian / mingguan
     */
    private function validateSave(DwPeriodData $data, DwPay $pay, array $employees): void
    {
        $settings = $pay->settings;
        $errors = [];
        $seen = [];

        foreach ($employees as $i => $employee) {
            $id = $employee['employee_id'];
            $item = $data->employees->get($id);
            $name = $item?->employee->name ?? 'Karyawan';

            if ($item === null) {
                $errors["employees.{$i}"][] = self::SKIP_NOT_COUNTED;

                continue;
            }
            if (isset($seen[$id])) {
                $errors["employees.{$i}"][] = "{$name}: karyawan ini dikirim lebih dari sekali";

                continue;
            }
            $seen[$id] = true;

            $accepted = [];
            foreach ($employee['entries'] as $j => $entry) {
                $key = "employees.{$i}.entries.{$j}";
                $label = $name.', '.Carbon::parse($entry['date'])->format('d/m/Y');
                $entry['duration_minutes'] = OvertimeRules::minutes($entry['start_time'], $entry['end_time']);

                if ($error = $data->candidateError($item, $entry['date'])) {
                    $errors[$key][] = "{$label}: {$error}";
                } elseif ($entry['start_time'] === $entry['end_time']) {
                    $errors[$key][] = "{$label}: durasi lembur 0";
                } elseif ($other = collect($accepted)->first(fn (array $saved) => $saved['date'] === $entry['date'] && OvertimeRules::overlaps($entry, $saved))) {
                    $errors[$key][] = "{$label}: tumpang tindih dengan ".OvertimeRules::label($other);
                } else {
                    $accepted[$j] = $entry;
                }
            }

            // Batas harian & mingguan: lembur yang tetap ada + hasil rekomendasi.
            $all = [...$data->fixedEntries($id), ...array_values($accepted)];
            foreach ($accepted as $j => $entry) {
                $week = Carbon::parse($entry['date'])->startOfWeek(Carbon::MONDAY)->toDateString();
                $day = array_sum(array_column(array_filter($all, fn (array $other) => $other['date'] === $entry['date']), 'duration_minutes'));
                $weekMinutes = array_sum(array_column(array_filter($all, fn (array $other) => Carbon::parse($other['date'])->startOfWeek(Carbon::MONDAY)->toDateString() === $week), 'duration_minutes'));
                $key = "employees.{$i}.entries.{$j}";
                $label = $name.', '.Carbon::parse($entry['date'])->format('d/m/Y');

                if ($day > $settings->max_hours_per_day * 60) {
                    $errors[$key][] = "{$label}: lebih dari {$settings->max_hours_per_day} jam lembur pada tanggal ini";
                }
                if ($weekMinutes > $settings->max_hours_per_week * 60) {
                    $errors[$key][] = "{$label}: lebih dari {$settings->max_hours_per_week} jam lembur pada minggu ini";
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn (array $messages) => array_values(array_unique($messages)), $errors));
        }
    }

    /** Batch Excel Lembur DW aktif yang tidak lagi punya baris (semua diganti rekomendasi) -> "diganti". */
    private function retireEmptyExcelBatches(AttendancePeriod $period): void
    {
        AttendanceImportBatch::query()
            ->where('period', $period->period)
            ->where('import_type', AttendanceImportType::DailyWorkerOvertime)
            ->where('status', ImportBatchStatus::Active)
            ->whereDoesntHave('overtimeEntries')
            ->update(['status' => ImportBatchStatus::Replaced]);
    }

    private function ensureNotLocked(AttendancePeriod $period): void
    {
        if ($period->isLocked()) {
            throw ValidationException::withMessages(['period' => "Periode upah {$period->label()} sudah dikunci. Lembur bulan itu tidak bisa diubah."]);
        }
    }

    private static function addMinutes(string $time, int $minutes): string
    {
        $total = ((int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2) + $minutes) % 1440;

        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }
}
