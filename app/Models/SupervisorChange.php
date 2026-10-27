<?php

namespace App\Models;

use App\Enums\SupervisorChangeSide;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Satu catatan perubahan atasan sebuah penugasan (tabel supervisor_changes).
 * Daftar atasan sebelum / sesudah disimpan di supervisor_change_details (side = Sebelum / Sesudah).
 */
#[Fillable(['assignment_id', 'source', 'transfer_id', 'termination_id', 'vacated_position_id', 'notes', 'changed_by', 'changed_at'])]
class SupervisorChange extends Model
{
    use HasUuids;

    /** Sumber perubahan lewat tombol "Ubah Atasan". */
    public const SOURCE_MANUAL = 'Manual';

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(PositionAssignment::class, 'assignment_id');
    }

    /** Usulan mutasi penyebabnya (null = Manual / Penonaktifan). */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /** Atasan SEBELUM diubah. */
    public function previousSupervisors(): BelongsToMany
    {
        return $this->supervisorsOnSide(SupervisorChangeSide::Before);
    }

    /** Atasan SESUDAH diubah. */
    public function newSupervisors(): BelongsToMany
    {
        return $this->supervisorsOnSide(SupervisorChangeSide::After);
    }

    private function supervisorsOnSide(SupervisorChangeSide $side): BelongsToMany
    {
        return $this->belongsToMany(PositionAssignment::class, 'supervisor_change_details', 'supervisor_change_id', 'supervisor_assignment_id')
            ->wherePivot('side', $side->value)
            ->withPivotValue('side', $side->value);
    }
}
