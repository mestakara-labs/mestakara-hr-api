<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengaturan Lembur (tabel overtime_settings, SATU baris). Ambil selalu lewat OvertimeSetting::current().
 * Penanda tangan SPKL & Daftar Upah diatur terpisah di Penanda Tangan (SignatureSetting).
 */
#[Fillable([
    'hourly_divisor', 'first_hour_multiplier', 'next_hour_multiplier', 'max_hours_per_day', 'max_hours_per_week',
    'rounding', 'dw_default_start_time', 'dw_default_description', 'time_step_minutes', 'updated_by',
])]
class OvertimeSetting extends Model
{
    use HasUuids;

    /** Nilai bawaan saat pengaturan pertama kali dibuat. */
    public const DEFAULTS = [
        'hourly_divisor' => 173,
        'first_hour_multiplier' => 1.5,
        'next_hour_multiplier' => 2,
        'max_hours_per_day' => 4,
        'max_hours_per_week' => 18,
        'rounding' => 100,
        'dw_default_start_time' => '17:00',
        'dw_default_description' => null,   // tidak ada teks bawaan, wajib diisi admin
        'time_step_minutes' => 30,
    ];

    protected function casts(): array
    {
        return [
            'first_hour_multiplier' => 'float',
            'next_hour_multiplier' => 'float',
        ];
    }

    /** Pengaturan yang berlaku (dibuat dengan nilai bawaan kalau belum ada). */
    public static function current(): self
    {
        return self::query()->with('updater')->first()
            ?? self::create(self::DEFAULTS)->load('updater');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
