<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceCode;
use App\Enums\OvertimeSource;
use App\Enums\OvertimeType;
use App\Models\AttendanceDay;
use App\Models\EmployeeWageScheme;
use App\Models\OvertimeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * DATA SATU PERIODE untuk Lembur DW, dimuat sekali (tanpa N+1) lalu dipakai bersama oleh daftar target,
 * rekomendasi, dan validasi simpan:
 *
 *   employees   karyawan Casual & DW yang aktif dalam periode (AttendanceEmployees)
 *   codes       [id karyawan][tanggal] => kode daftar hadir
 *   entries     [id karyawan] => semua lembur tersimpan di minggu-minggu yang menyentuh periode (untuk batas mingguan)
 *
 * Satu lembur = array { id, employee_id, date, start_time "HH:MM", end_time, duration_minutes, overtime_type, source, import_batch_id }.
 */
class DwPeriodData
{
    /** Sumber Lembur DW yang saling menggantikan (Excel & rekomendasi); manual & mobile tidak pernah dihapus otomatis. */
    public const REPLACEABLE_SOURCES = [OvertimeSource::ImportExcel, OvertimeSource::SystemRecommendation];

    /**
     * @param  Collection<string, PeriodEmployee>  $employees
     * @param  array<string, array<string, string>>  $codes
     * @param  array<string, list<array>>  $entries
     */
    private function __construct(
        public readonly AttendancePeriod $period,
        public readonly Collection $employees,
        public readonly array $codes,
        public readonly array $entries,
    ) {}

    public static function load(AttendancePeriod $period, AttendanceEmployees $attendanceEmployees): self
    {
        $employees = $attendanceEmployees->forPeriod($period);

        $codes = [];
        AttendanceDay::query()
            ->where('date', '>=', $period->start()->toDateString())
            ->where('date', '<', $period->end()->addDay()->toDateString())
            ->get(['employee_id', 'date', 'status_code'])
            ->each(function (AttendanceDay $day) use (&$codes) {
                $codes[$day->employee_id][$day->date->toDateString()] = $day->status_code->value;
            });

        [$from, $to] = OvertimeRules::weekBounds($period);
        $entries = [];
        OvertimeEntry::query()
            ->where('date', '>=', $from)
            ->where('date', '<', Carbon::parse($to)->addDay()->toDateString())
            ->orderBy('date')
            ->orderBy('start_time')
            ->get()
            ->each(function (OvertimeEntry $entry) use (&$entries) {
                $entries[$entry->employee_id][] = self::entryArray($entry);
            });

        return new self($period, $employees, $codes, $entries);
    }

    public static function entryArray(OvertimeEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'employee_id' => $entry->employee_id,
            'date' => $entry->date->toDateString(),
            'start_time' => substr($entry->start_time, 0, 5),
            'end_time' => substr($entry->end_time, 0, 5),
            'duration_minutes' => $entry->duration_minutes,
            'overtime_type' => $entry->overtime_type->value,
            'source' => $entry->source->value,
            'import_batch_id' => $entry->import_batch_id,
        ];
    }

    /** Jumlah hari berkode DW di daftar hadir periode itu. */
    public function dwDays(string $employeeId): int
    {
        return count(array_keys($this->codes[$employeeId] ?? [], AttendanceCode::DailyWorker->value, true));
    }

    /** Skema upah pada tanggal aktif TERAKHIR karyawan di periode itu (null = belum diatur). */
    public function scheme(PeriodEmployee $item): ?EmployeeWageScheme
    {
        $dates = $item->activeDates();

        return $dates === [] ? null : $item->employee->wageSchemeOn(Carbon::parse(end($dates)));
    }

    /** @return list<array> lembur DW karyawan itu di dalam periode (semua sumber) */
    public function dwEntries(string $employeeId): array
    {
        return array_values(array_filter(
            $this->entries[$employeeId] ?? [],
            fn (array $entry) => $entry['overtime_type'] === OvertimeType::DailyWorker->value && $this->period->contains($entry['date']),
        ));
    }

    /** @return list<array> lembur DW bersumber manual / mobile di dalam periode (dipertahankan) */
    public function keptDwEntries(string $employeeId): array
    {
        return array_values(array_filter($this->dwEntries($employeeId), fn (array $entry) => ! self::isReplaceable($entry)));
    }

    /** @return list<array> lembur DW bersumber Excel / rekomendasi di dalam periode (akan diganti) */
    public function replaceableDwEntries(string $employeeId): array
    {
        return array_values(array_filter($this->dwEntries($employeeId), fn (array $entry) => self::isReplaceable($entry)));
    }

    /**
     * Lembur yang TETAP ADA sesudah lembur DW Excel/rekomendasi karyawan itu di periode ini diganti:
     * semua lembur reguler, lembur DW manual/mobile, dan semua lembur di luar periode (bulan sebelah).
     *
     * @return list<array>
     */
    public function fixedEntries(string $employeeId): array
    {
        return array_values(array_filter(
            $this->entries[$employeeId] ?? [],
            fn (array $entry) => ! ($entry['overtime_type'] === OvertimeType::DailyWorker->value
                && self::isReplaceable($entry)
                && $this->period->contains($entry['date'])),
        ));
    }

    /**
     * Tanggal yang BOLEH diberi Lembur DW rekomendasi: karyawan aktif, kode K atau DW, tidak ada lembur reguler,
     * tidak ada lembur DW manual/mobile.
     *
     * @return list<string>
     */
    public function candidateDates(PeriodEmployee $item): array
    {
        $id = $item->employee->id;
        $busy = [];
        foreach ($this->fixedEntries($id) as $entry) {
            if ($this->period->contains($entry['date'])) {
                $busy[$entry['date']] = true;
            }
        }

        return array_values(array_filter($item->activeDates(), fn (string $date) => in_array($this->codes[$id][$date] ?? null, [AttendanceCode::Work->value, AttendanceCode::DailyWorker->value], true)
            && ! isset($busy[$date])));
    }

    /** Alasan tanggal itu TIDAK boleh diberi Lembur DW rekomendasi, atau null kalau boleh. */
    public function candidateError(PeriodEmployee $item, string $date): ?string
    {
        $id = $item->employee->id;
        if (! $this->period->contains($date)) {
            return 'Tanggal di luar periode '.$this->period->label();
        }
        if (! $item->isActiveOn($date)) {
            return 'Karyawan tidak aktif pada tanggal ini';
        }
        $code = $this->codes[$id][$date] ?? null;
        if (! in_array($code, [AttendanceCode::Work->value, AttendanceCode::DailyWorker->value], true)) {
            return $code === null ? 'Tanggal ini belum berkode di daftar hadir (harus K atau DW)' : "Tanggal ini berkode {$code} (harus K atau DW)";
        }
        foreach ($this->fixedEntries($id) as $entry) {
            if ($entry['date'] === $date) {
                return $entry['overtime_type'] === OvertimeType::Regular->value
                    ? 'Tanggal ini sudah ada lembur reguler'
                    : 'Tanggal ini sudah ada lembur DW manual/mobile';
            }
        }

        return null;
    }

    public static function isReplaceable(array $entry): bool
    {
        return in_array($entry['source'], array_map(fn (OvertimeSource $source) => $source->value, self::REPLACEABLE_SOURCES), true);
    }
}
