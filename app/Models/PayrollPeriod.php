<?php

namespace App\Models;

use App\Enums\PayrollPeriodStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Status periode upah satu bulan (tabel payroll_periods): Draf / Dikunci.
 * Bulan yang belum punya baris dianggap Draf.
 */
#[Fillable(['period', 'status', 'locked_at', 'locked_by'])]
class PayrollPeriod extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'status' => PayrollPeriodStatus::class,
            'locked_at' => 'datetime',
        ];
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /** Apakah periode upah yang memuat tanggal ini sudah dikunci? */
    public static function isLockedOn(Carbon|string $date): bool
    {
        return self::query()
            ->where('period', Carbon::parse($date)->format('Y-m'))
            ->where('status', PayrollPeriodStatus::Locked)
            ->exists();
    }

    /** Pastikan periode upah tanggal ini belum dikunci; kalau sudah, tolak dengan 409. */
    public static function ensureNotLockedOn(Carbon|string $date): void
    {
        $period = Carbon::parse($date)->format('Y-m');

        abort_if(self::isLockedOn($date), 409, "Periode upah {$period} sudah dikunci. Data absensi & lembur bulan itu tidak bisa diubah.");
    }
}
