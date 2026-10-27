<?php

namespace App\Models;

use App\Enums\RotationMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Regu kerja (tabel work_groups): sekelompok karyawan yang bergantian mengisi shift dengan satu pola.
 *
 * anchor_date = Tanggal Mulai Berlaku Jadwal = hari ke-1 pola & putaran ke-0 (lihat App\Services\Schedule\Rotation).
 */
#[Fillable([
    'name', 'sub_unit_id', 'placement_object_id', 'work_pattern_id', 'leader_employee_id',
    'rotation_mode', 'rotate_every_days', 'anchor_date', 'description',
])]
class WorkGroup extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'rotation_mode' => RotationMode::class,
            'rotate_every_days' => 'integer',
            'anchor_date' => 'date',
        ];
    }

    public function subUnit(): BelongsTo
    {
        return $this->belongsTo(SubUnit::class);
    }

    public function placementObject(): BelongsTo
    {
        return $this->belongsTo(PlacementObject::class);
    }

    public function workPattern(): BelongsTo
    {
        return $this->belongsTo(WorkPattern::class);
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'leader_employee_id');
    }

    /** Semua anggota (aktif maupun riwayat). */
    public function members(): HasMany
    {
        return $this->hasMany(WorkGroupMember::class);
    }

    public function schedulePeriods(): HasMany
    {
        return $this->hasMany(SchedulePeriod::class);
    }
}
