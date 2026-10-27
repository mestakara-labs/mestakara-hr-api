<?php

namespace App\Services\Attendance;

use App\Models\EmployeeWageScheme;
use App\Models\OvertimeSetting;

/**
 * PERHITUNGAN UPAH LEMBUR DW (semua angka dari Pengaturan Lembur):
 *
 *   Rp/hari     = Gaji Pokok ÷ HKE
 *   target      = hari DW × Rp/hari, dibulatkan ke rupiah
 *   tarif/jam   = round(Gaji Pokok ÷ pembagi upah sejam)
 *   per TANGGAL (jam semua baris DW tanggal itu dijumlahkan dulu):
 *     jam dibayar = min(jam, 1) × pengali jam pertama + max(jam − 1, 0) × pengali jam berikutnya
 *     upah        = tarif × jam dibayar, dibulatkan ke kelipatan pembulatan
 *   selisih     = upah lembur DW − target
 *   status      skema_belum_diatur · belum_ada (hari DW > 0, belum ada lembur DW) ·
 *               sesuai (|selisih| ≤ round(tarif × pengali jam berikutnya × kelipatan menit / 60)) · kurang · lebih
 */
class DwPay
{
    public const STATUS_NO_SCHEME = 'skema_belum_diatur';

    public const STATUS_NONE = 'belum_ada';

    public const STATUS_MATCH = 'sesuai';

    public const STATUS_SHORT = 'kurang';

    public const STATUS_OVER = 'lebih';

    public function __construct(public readonly OvertimeSetting $settings) {}

    public static function make(): self
    {
        return new self(OvertimeSetting::current());
    }

    public function hourlyRate(EmployeeWageScheme $scheme): int
    {
        return (int) round($scheme->base_salary / $this->settings->hourly_divisor);
    }

    /** Upah lembur DW satu tanggal dari total menitnya. */
    public function payForMinutes(EmployeeWageScheme $scheme, int $minutes): int
    {
        if ($minutes <= 0) {
            return 0;
        }

        $hours = $minutes / 60;
        $paidHours = min($hours, 1) * $this->settings->first_hour_multiplier
            + max($hours - 1, 0) * $this->settings->next_hour_multiplier;

        return $this->roundToStep($this->hourlyRate($scheme) * $paidHours);
    }

    /**
     * Upah lembur DW beberapa baris: dijumlahkan per tanggal dulu, lalu dihitung per tanggal.
     *
     * @param  iterable<array{date: string, duration_minutes: int}>  $entries
     */
    public function payForEntries(EmployeeWageScheme $scheme, iterable $entries): int
    {
        return array_sum(array_map(fn (int $minutes) => $this->payForMinutes($scheme, $minutes), $this->minutesPerDate($entries)));
    }

    /**
     * @param  iterable<array{date: string, duration_minutes: int}>  $entries
     * @return array<string, int> [tanggal => total menit]
     */
    public function minutesPerDate(iterable $entries): array
    {
        $minutes = [];
        foreach ($entries as $entry) {
            $minutes[$entry['date']] = ($minutes[$entry['date']] ?? 0) + $entry['duration_minutes'];
        }

        return $minutes;
    }

    /** Target upah = hari DW × (Gaji Pokok ÷ HKE), dibulatkan ke rupiah. */
    public function target(EmployeeWageScheme $scheme, int $dwDays): int
    {
        return (int) round($dwDays * $scheme->base_salary / $scheme->working_days);
    }

    /** Selisih yang masih dianggap "sesuai": satu kelipatan waktu dengan pengali jam berikutnya. */
    public function tolerance(EmployeeWageScheme $scheme): int
    {
        return (int) round($this->hourlyRate($scheme) * $this->settings->next_hour_multiplier * $this->settings->time_step_minutes / 60);
    }

    public function status(?EmployeeWageScheme $scheme, int $dwDays, int $entryCount, int $pay, int $target): string
    {
        return match (true) {
            $scheme === null => self::STATUS_NO_SCHEME,
            $dwDays > 0 && $entryCount === 0 => self::STATUS_NONE,
            abs($pay - $target) <= $this->tolerance($scheme) => self::STATUS_MATCH,
            $pay < $target => self::STATUS_SHORT,
            default => self::STATUS_OVER,
        };
    }

    private function roundToStep(float $amount): int
    {
        $step = max((int) $this->settings->rounding, 1);

        return (int) (round($amount / $step) * $step);
    }
}
