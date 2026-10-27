<?php

namespace App\Services\Attendance;

use App\Models\EmployeeWageScheme;
use Illuminate\Support\Carbon;
use Random\Randomizer;

/**
 * MENYEBAR LEMBUR DW REKOMENDASI untuk satu karyawan (acak setiap kali dipanggil), dalam kelipatan
 * time_step_minutes, sampai upah lembur DW mendekati target:
 *
 *   1. acak urutan tanggal kandidat
 *   2. per tanggal: beri 1 / 1,5 / 2 jam acak (dipotong batas), berhenti begitu upah ≥ target
 *   3. masih kurang: tambah satu kelipatan ke tanggal acak yang masih punya sisa batas, sampai ≥ target / batas habis
 *   4. penyesuaian akhir: kurangi tanggal terakhir yang diubah per kelipatan selama hasilnya LEBIH DEKAT ke target
 *
 * Batas: maks. jam per tanggal, dan maks. jam per minggu (Senin–Minggu) termasuk lembur yang tetap ada
 * (reguler, DW manual/mobile, bulan sebelah). Upah dihitung DwPay; upah lembur yang tetap ada = titik awal.
 */
class DwRecommender
{
    public const NOTE_KEPT_ENOUGH = 'Lembur DW manual/mobile sudah mencukupi target';

    public const NOTE_NOT_ENOUGH = 'Tanggal yang memenuhi syarat tidak cukup untuk mencapai target';

    /** Pilihan jam awal per tanggal (menit). */
    private const FIRST_CHOICES = [60, 90, 120];

    private Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer;
    }

    /**
     * @param  list<string>  $candidates  tanggal yang boleh diisi
     * @param  list<array>  $fixedEntries  lembur yang tetap ada (untuk batas mingguan)
     * @return array{minutes: array<string, int>, notes: list<string>} menit per tanggal (urut tanggal) + catatan
     */
    public function recommend(DwPay $pay, EmployeeWageScheme $scheme, int $target, int $keptPay, array $candidates, array $fixedEntries): array
    {
        if ($keptPay >= $target) {
            return ['minutes' => [], 'notes' => [self::NOTE_KEPT_ENOUGH]];
        }

        $settings = $pay->settings;
        $step = max((int) $settings->time_step_minutes, 1);
        $dayCap = $settings->max_hours_per_day * 60;
        $weekCap = $settings->max_hours_per_week * 60;

        $weekUsed = [];
        foreach ($fixedEntries as $entry) {
            $week = self::week($entry['date']);
            $weekUsed[$week] = ($weekUsed[$week] ?? 0) + $entry['duration_minutes'];
        }

        $minutes = [];
        $remaining = function (string $date) use (&$minutes, &$weekUsed, $dayCap, $weekCap, $step): int {
            $free = min($dayCap - ($minutes[$date] ?? 0), $weekCap - ($weekUsed[self::week($date)] ?? 0));

            return max(intdiv($free, $step) * $step, 0);
        };
        $add = function (string $date, int $amount) use (&$minutes, &$weekUsed): void {
            $minutes[$date] = ($minutes[$date] ?? 0) + $amount;
            $weekUsed[self::week($date)] = ($weekUsed[self::week($date)] ?? 0) + $amount;
        };
        $total = function () use (&$minutes, $pay, $scheme, $keptPay): int {
            return $keptPay + array_sum(array_map(fn (int $value) => $pay->payForMinutes($scheme, $value), $minutes));
        };

        // 1–2. Acak urutan tanggal, beri jam awal acak per tanggal sampai target tercapai.
        $dates = $candidates === [] ? [] : $this->randomizer->shuffleArray($candidates);
        $last = null;
        foreach ($dates as $date) {
            if ($total() >= $target) {
                break;
            }
            $amount = min(self::FIRST_CHOICES[$this->randomizer->getInt(0, count(self::FIRST_CHOICES) - 1)], $remaining($date));
            $amount = intdiv($amount, $step) * $step;
            if ($amount > 0) {
                $add($date, $amount);
                $last = $date;
            }
        }

        // 3. Masih kurang: tambah satu kelipatan ke tanggal acak yang masih punya sisa batas.
        $exhausted = false;
        while ($total() < $target) {
            $open = array_values(array_filter($dates, fn (string $date) => $remaining($date) >= $step));
            if ($open === []) {
                $exhausted = true;
                break;
            }
            $date = $open[$this->randomizer->getInt(0, count($open) - 1)];
            $add($date, $step);
            $last = $date;
        }

        // 4. Kurangi tanggal terakhir yang diubah selama hasilnya lebih dekat ke target.
        while ($last !== null && ($minutes[$last] ?? 0) > 0) {
            $current = abs($total() - $target);
            $add($last, -$step);
            if (abs($total() - $target) < $current) {
                if ($minutes[$last] === 0) {
                    unset($minutes[$last]);
                }

                continue;
            }
            $add($last, $step);   // tidak lebih dekat: kembalikan
            break;
        }

        ksort($minutes);

        return ['minutes' => $minutes, 'notes' => $exhausted ? [self::NOTE_NOT_ENOUGH] : []];
    }

    /** Tanggal Senin dari minggu (Senin–Minggu) yang memuat tanggal itu. */
    private static function week(string $date): string
    {
        return Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString();
    }
}
