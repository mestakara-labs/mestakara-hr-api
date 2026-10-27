<?php

namespace App\Models;

use App\Models\Concerns\HasStatusDocument;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Satu karyawan di usulan Penambahan (tabel employee_activation_items):
 * dasar penambahan, TMT aktif, dokumen per orang, dan rencana penempatan (opsional).
 */
#[Fillable([
    'activation_id', 'employee_id', 'type', 'effective_date', 'origin_unit', 'previous_termination_item_id',
    'document_type', 'document_number', 'document_date', 'document_file_id',
    'signer_source', 'signer_assignment_id', 'signer_name', 'signer_position',
    'position_id', 'assignment_status', 'sub_unit_id', 'assignment_end_date', 'new_assignment_id',
    'user_reactivated', 'notes',
])]
class EmployeeActivationItem extends Model
{
    use HasStatusDocument, HasUuids;

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'document_date' => 'date',
            'assignment_end_date' => 'date',
            'user_reactivated' => 'boolean',
        ];
    }

    public function activation(): BelongsTo
    {
        return $this->belongsTo(EmployeeActivation::class, 'activation_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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
        return $this->belongsToMany(PlacementObject::class, 'activation_item_placement_objects', 'activation_item_id')
            ->orderBy('placement_objects.id');
    }

    public function supervisors(): BelongsToMany
    {
        return $this->belongsToMany(PositionAssignment::class, 'activation_item_supervisors', 'activation_item_id', 'supervisor_assignment_id');
    }

    /** Penugasan yang dibuat saat usulan Berlaku. */
    public function newAssignment(): BelongsTo
    {
        return $this->belongsTo(PositionAssignment::class, 'new_assignment_id');
    }

    /** Ada rencana penempatan? (jabatan, status, dan sub unit terisi) */
    public function hasPlacement(): bool
    {
        return $this->position_id !== null && $this->sub_unit_id !== null && $this->assignment_status !== null;
    }
}
