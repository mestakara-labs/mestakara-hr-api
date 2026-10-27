<?php

namespace App\Models;

use App\Enums\DocumentScope;
use App\Enums\ProposalStatus;
use App\Models\Concerns\HasStatusDocument;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Usulan Penonaktifan Karyawan (tabel employee_terminations): satu atau banyak karyawan Aktif menjadi NONAKTIF.
 * Dokumen kolektif disimpan di sini; dokumen per orang di itemnya.
 *
 * Alur & aturannya ada di App\Services\Termination\TerminationService.
 */
#[Fillable([
    'status', 'document_scope', 'document_type', 'document_number', 'document_date', 'document_file_id',
    'signer_source', 'signer_assignment_id', 'signer_name', 'signer_position', 'notes',
    'created_by', 'activated_at', 'cancelled_at', 'cancelled_by', 'cancel_reason',
])]
class EmployeeTermination extends Model
{
    use HasStatusDocument, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => ProposalStatus::class,
            'document_scope' => DocumentScope::class,
            'document_date' => 'date',
            'activated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmployeeTerminationItem::class, 'termination_id')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Perubahan atasan otomatis karena usulan ini (dan pengembaliannya saat dibatalkan). */
    public function supervisorChanges(): HasMany
    {
        return $this->hasMany(SupervisorChange::class, 'termination_id');
    }

    public function isCollective(): bool
    {
        return $this->document_scope === DocumentScope::Collective;
    }

    /** Sumber di Riwayat Atasan, mis. "Penonaktifan SK/123". */
    public function supervisorChangeSource(): string
    {
        return trim('Penonaktifan '.$this->document_number);
    }
}
