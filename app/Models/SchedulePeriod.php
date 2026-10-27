<?php

namespace App\Models;

use App\Enums\SchedulePeriodStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Periode jadwal (tabel schedule_periods) = jadwal resmi satu regu untuk satu bulan.
 * Status Draf -> Terbit -> Terkunci (lihat App\Enums\SchedulePeriodStatus).
 */
#[Fillable([
    'work_group_id', 'month', 'status', 'generated_at', 'generated_by',
    'published_at', 'published_by', 'locked_at', 'locked_by',
])]
class SchedulePeriod extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'status' => SchedulePeriodStatus::class,
            'generated_at' => 'datetime',
            'published_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function workGroup(): BelongsTo
    {
        return $this->belongsTo(WorkGroup::class);
    }

    /** Baris jadwal: satu per anggota per tanggal. */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
