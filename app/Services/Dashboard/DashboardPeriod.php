<?php

namespace App\Services\Dashboard;

use App\Support\DataPeriod;
use Illuminate\Support\Carbon;

/**
 * PERIODE DASHBOARD: rentang bulan utuh from..to.
 *
 *   from  tanggal 1 bulan pertama
 *   to    tanggal terakhir bulan terakhir; untuk bulan berjalan = hari ini
 *   bawaan (keduanya kosong) = 1 Januari tahun berjalan s.d. hari ini
 *
 * Angka AKUMULASI dijumlahkan per bulan dalam rentang; angka POSISI dihitung pada tanggal `to`.
 * previous() = rentang sebelumnya yang sama panjang, mis. Jan–Okt 2026 -> Mar–Des 2025.
 * Validasi (maks. 24 bulan, from ≤ to, bukan masa depan) ada di DashboardRequest.
 */
class DashboardPeriod
{
    public const MAX_MONTHS = 24;

    private const SHORT_MONTH_NAMES = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    private function __construct(public readonly Carbon $from, public readonly Carbon $to) {}

    /** Dari isian from/to (YYYY-MM-DD, boleh kosong); dibulatkan ke bulan utuh. */
    public static function make(?string $from, ?string $to): self
    {
        $today = DataPeriod::today();
        $toDate = $to === null ? $today->copy() : Carbon::parse($to);
        // from kosong = 1 Januari tahun `to` (bawaan: tahun berjalan).
        $fromDate = $from === null ? $toDate->copy()->startOfYear() : Carbon::parse($from);

        return self::ofMonths(Carbon::parse($fromDate->format('Y-m-01')), Carbon::parse($toDate->format('Y-m-01')));
    }

    /** Rentang dari bulan pertama s.d. bulan terakhir (keduanya tanggal 1). */
    private static function ofMonths(Carbon $firstMonth, Carbon $lastMonth): self
    {
        $end = $lastMonth->copy()->endOfMonth()->startOfDay();
        $today = DataPeriod::today();

        return new self(Carbon::parse($firstMonth->toDateString()), Carbon::parse(($end->greaterThan($today) ? $today : $end)->toDateString()));
    }

    /** Rentang sebelumnya dengan jumlah bulan yang sama. */
    public function previous(): self
    {
        $count = count($this->months());
        $lastMonth = Carbon::parse($this->from->format('Y-m-01'))->subMonthNoOverflow();
        $firstMonth = $lastMonth->copy()->subMonthsNoOverflow($count - 1);

        return self::ofMonths($firstMonth, $lastMonth);
    }

    /** @return list<string> bulan dalam rentang, "YYYY-MM" */
    public function months(): array
    {
        $months = [];
        for ($month = Carbon::parse($this->from->format('Y-m-01')); $month->lessThanOrEqualTo($this->to); $month->addMonthNoOverflow()) {
            $months[] = $month->format('Y-m');
        }

        return $months;
    }

    /** "Jan – Okt 2026" atau "Mar 2025 – Feb 2026". */
    public function label(): string
    {
        $from = self::SHORT_MONTH_NAMES[$this->from->month];
        $to = self::SHORT_MONTH_NAMES[$this->to->month].' '.$this->to->year;

        if ($this->from->format('Y-m') === $this->to->format('Y-m')) {
            return $to;
        }

        return $this->from->year === $this->to->year ? "{$from} – {$to}" : "{$from} {$this->from->year} – {$to}";
    }

    /** "Okt 2026" untuk kategori bulan. */
    public static function monthLabel(string $month): string
    {
        $date = Carbon::parse($month.'-01');

        return self::SHORT_MONTH_NAMES[$date->month].' '.$date->year;
    }

    /** @return array{from: string, to: string} */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }
}
