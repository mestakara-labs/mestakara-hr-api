<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Satu karyawan di usulan mutasi (tabel transfer_items):
 * penugasan lama yang diakhiri (from) + rencana penugasan baru (position, sub unit, objek, atasan).
 */
#[Fillable([
    'transfer_id', 'employee_id', 'from_assignment_id', 'position_id', 'assignment_status', 'sub_unit_id',
    'end_date', 'notes', 'restore_subordinates', 'excerpt_file_id', 'new_assignment_id',
])]
class TransferItem extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'end_date' => 'date',
            'restore_subordinates' => 'boolean',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Penugasan lama yang diakhiri; null = tidak ada (Penempatan Awal / merangkap). */
    public function fromAssignment(): BelongsTo
    {
        return $this->belongsTo(PositionAssignment::class, 'from_assignment_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function subUnit(): BelongsTo
    {
        return $this->belongsTo(SubUnit::class);
    }

    public function placementObjects(): BelongsToMany
    {
        return $this->belongsToMany(PlacementObject::class, 'transfer_item_placement_objects')->orderBy('placement_objects.id');
    }

    /** Atasan rencana penugasan baru (penugasan milik atasan). */
    public function supervisors(): BelongsToMany
    {
        return $this->belongsToMany(PositionAssignment::class, 'transfer_item_supervisors', 'transfer_item_id', 'supervisor_assignment_id');
    }

    public function excerptFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'excerpt_file_id');
    }

    /** Penugasan baru yang dibuat saat usulan Berlaku. */
    public function newAssignment(): BelongsTo
    {
        return $this->belongsTo(PositionAssignment::class, 'new_assignment_id');
    }
}
