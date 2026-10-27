<?php

namespace App\Models;

use App\Models\Concerns\HasStatusDocument;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Satu karyawan di usulan Penonaktifan (tabel employee_termination_items):
 * jenis, TMT nonaktif (hari pertama tidak aktif), alasan / unit tujuan, dan dokumen per orang.
 */
#[Fillable([
    'termination_id', 'employee_id', 'type', 'effective_date', 'reason', 'destination_unit',
    'document_type', 'document_number', 'document_date', 'document_file_id',
    'signer_source', 'signer_assignment_id', 'signer_name', 'signer_position',
    'user_deactivated', 'notes',
])]
class EmployeeTerminationItem extends Model
{
    use HasStatusDocument, HasUuids;

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'document_date' => 'date',
            'user_deactivated' => 'boolean',
        ];
    }

    public function termination(): BelongsTo
    {
        return $this->belongsTo(EmployeeTermination::class, 'termination_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Jabatan yang diakhiri saat usulan Berlaku, beserta TMT selesai sebelumnya (untuk pembatalan). */
    public function endedAssignments(): BelongsToMany
    {
        return $this->belongsToMany(PositionAssignment::class, 'termination_ended_assignments', 'termination_item_id', 'assignment_id')
            ->withPivot('previous_end_date');
    }

    /** Keanggotaan regu kerja yang diakhiri saat usulan Berlaku, beserta tanggal terakhir sebelumnya. */
    public function endedMemberships(): BelongsToMany
    {
        return $this->belongsToMany(WorkGroupMember::class, 'termination_ended_memberships', 'termination_item_id', 'work_group_member_id')
            ->withPivot('previous_end_date');
    }
}
