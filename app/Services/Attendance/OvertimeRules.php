<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceCode;
use App\Models\OvertimeEntry;
use App\Models\OvertimeSetting;
use Illuminate\Support\Carbon;

/**
 * ATURAN LEMBUR yang dipakai bersama oleh pemeriksaan unggahan (OvertimeImportChecker) dan daftar lembur
 * (GET /overtime-entries):
 *
 *   - satu baris = rentang jam pada satu tanggal; jam selesai ≤ jam mulai = lewat tengah malam
 *   - dua baris karyawan yang sama pada tanggal yang sama tidak boleh tumpang tindih
 *   - total lembur (reguler + DW) > maks. jam per hari / per minggu (Senin–Minggu) = PERINGATAN
 *   - tanggal berkode I, S, M, C di daftar hadir = SALAH; berkode L atau kosong = PERINGATAN
 *
 * Satu "lembur" di sini berupa array: { employee_id, date "YYYY-MM-DD", start_time "HH:MM", end_time "HH:MM" }.
 */
class OvertimeRules
{
    /** Kode daftar hadir yang membuat lembur pada tanggal itu SALAH. */
    public const BLOCKING_CODES = ['I', 'S', 'M', 'C'];

    public function __construct(private OvertimeSetting $settings) {}

    public static function make(): self
    {
        return new self(OvertimeSetting::current());
    }

    /** Lama lembur (menit). */
    public static function minutes(string $startTime, string $endTime): int
    {
        return OvertimeEntry::minutesBetween($startTime, $endTime);
    }

    public static function crossesMidnight(string $startTime, string $endTime): bool
    {
        return substr($endTime, 0, 5) <= substr($startTime, 0, 5);
    }

    /** Dua lembur pada tanggal yang sama saling tumpang tindih? */
    public static function overlaps(array $a, array $b): bool
    {
        [$aStart, $aEnd] = self::range($a);
        [$bStart, $bEnd] = self::range($b);

        return $aStart < $bEnd && $bStart < $aEnd;
    }

    /** Teks rentang jam, mis. "17:00–19:00". */
    public static function label(array $entry): string
    {
        return substr($entry['start_time'], 0, 5).'–'.substr($entry['end_time'], 0, 5);
    }

    /**
     * Peringatan batas jam untuk satu karyawan pada satu tanggal, dari SEMUA lembur yang dihitung
     * (reguler + DW, termasuk tanggal di sekitarnya untuk batas mingguan).
     *
     * @param  list<array>  $entries  lembur karyawan itu
     * @return list<string>
     */
    public function limitWarnings(array $entries, string $date): array
    {
        $warnings = [];
        $dayMinutes = 0;
        $weekMinutes = 0;
        $weekStart = Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString();
        $weekEnd = Carbon::parse($date)->endOfWeek(Carbon::SUNDAY)->toDateString();

        foreach ($entries as $entry) {
            $minutes = self::minutes($entry['start_time'], $entry['end_time']);
            if ($entry['date'] === $date) {
                $dayMinutes += $minutes;
            }
            if ($entry['date'] >= $weekStart && $entry['date'] <= $weekEnd) {
                $weekMinutes += $minutes;
            }
        }

        if ($dayMinutes > $this->settings->max_hours_per_day * 60) {
            $warnings[] = 'Lebih dari '.$this->settings->max_hours_per_day.' jam lembur pada tanggal ini ('.self::hours($dayMinutes).' jam)';
        }
        if ($weekMinutes > $this->settings->max_hours_per_week * 60) {
            $warnings[] = 'Lebih dari '.$this->settings->max_hours_per_week.' jam lembur pada minggu ini ('.self::hours($weekMinutes).' jam)';
        }

        return $warnings;
    }

    /** Pesan SALAH untuk kode daftar hadir I/S/M/C pada tanggal lembur, atau null. */
    public static function dayCodeError(?string $code): ?string
    {
        return in_array($code, self::BLOCKING_CODES, true)
            ? "Tanggal ini berkode {$code} (".AttendanceCode::from($code)->label().') di daftar hadir'
            : null;
    }

    /** Pesan PERINGATAN untuk tanggal berkode L atau tanpa kode di daftar hadir, atau null. */
    public static function dayCodeWarning(?string $code): ?string
    {
        return match ($code) {
            null => 'Tanggal ini belum berkode di daftar hadir',
            AttendanceCode::DayOff->value => 'Tanggal ini berkode L (Libur) di daftar hadir',
            default => null,
        };
    }

    /** Batas minggu (Senin–Minggu) yang memuat periode, untuk mengambil lembur tersimpan di sekitarnya. */
    public static function weekBounds(AttendancePeriod $period): array
    {
        return [
            $period->start()->startOfWeek(Carbon::MONDAY)->toDateString(),
            $period->end()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        ];
    }

    /** @return array{int, int} rentang menit sejak 00:00 tanggal lembur */
    private static function range(array $entry): array
    {
        $start = self::clock($entry['start_time']);

        return [$start, $start + self::minutes($entry['start_time'], $entry['end_time'])];
    }

    private static function clock(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    private static function hours(int $minutes): string
    {
        return rtrim(rtrim(number_format($minutes / 60, 2, ',', ''), '0'), ',');
    }
}
