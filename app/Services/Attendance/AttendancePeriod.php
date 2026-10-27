<?php

namespace App\Services\Attendance;

use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use App\Support\DataPeriod;
use Illuminate\Support\Carbon;

/**
 * Satu PERIODE ABSENSI "YYYY-MM" (bulan kalender). Periode sudah divalidasi Form Request dengan
 * DataPeriod::rules() (maksimal bulan berjalan).
 *
 *   $period = new AttendancePeriod('2026-10');
 *   $period->dates();            // ['2026-10-01', ..., '2026-10-31']
 *   $period->ensureNotLocked();  // 423 kalau periode upah sudah dikunci
 */
class AttendancePeriod
{
    /** Nama hari singkat, Minggu = 0. */
    public const DAY_NAMES = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];

    public function __construct(public readonly string $period) {}

    public function start(): Carbon
    {
        return Carbon::parse($this->period.'-01');
    }

    public function end(): Carbon
    {
        return $this->start()->endOfMonth()->startOfDay();
    }

    /** @return list<string> semua tanggal periode, "YYYY-MM-DD" */
    public function dates(): array
    {
        $dates = [];
        for ($date = $this->start(); $date->lessThanOrEqualTo($this->end()); $date->addDay()) {
            $dates[] = $date->toDateString();
        }

        return $dates;
    }

    public function contains(Carbon|string $date): bool
    {
        return Carbon::parse($date)->format('Y-m') === $this->period;
    }

    /** Tanggal acuan untuk jabatan & skema: hari ini (bulan berjalan) atau tanggal terakhir bulan itu. */
    public function referenceDate(): Carbon
    {
        return DataPeriod::asOf($this->period);
    }

    /** Mis. "Oktober 2026". */
    public function label(): string
    {
        return DataPeriod::label($this->period);
    }

    public function status(): PayrollPeriodStatus
    {
        return PayrollPeriod::where('period', $this->period)->value('status') ?? PayrollPeriodStatus::Draft;
    }

    public function isLocked(): bool
    {
        return $this->status() === PayrollPeriodStatus::Locked;
    }

    /** Tolak unggah & koreksi pada periode upah yang sudah dikunci (423). */
    public function ensureNotLocked(): void
    {
        abort_if($this->isLocked(), 423, "Periode upah {$this->label()} sudah dikunci. Absensi & lembur bulan itu tidak bisa diubah.");
    }
}
