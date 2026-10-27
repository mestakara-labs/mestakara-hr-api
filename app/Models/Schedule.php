<?php

namespace App\Models;

use App\Enums\ScheduleSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu baris jadwal harian (tabel schedules): satu anggota pada satu tanggal — dasar absensi nanti.
 * Jam rencana (planned_start/end) adalah SALINAN jam shift saat jadwal dibuat.
 */
#[Fillable([
    'schedule_period_id', 'work_group_member_id', 'employee_id', 'work_date', 'shift_id',
    'planned_start', 'planned_end', 'slot_code', 'generated_shift_id', 'source',
])]
class Schedule extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'planned_start' => 'datetime',
            'planned_end' => 'datetime',
            'source' => ScheduleSource::class,
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(SchedulePeriod::class, 'schedule_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Riwayat ubahan sel ini. */
    public function changes(): HasMany
    {
        return $this->hasMany(ScheduleChange::class);
    }
}
