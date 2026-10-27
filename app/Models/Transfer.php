<?php

namespace App\Models;

use App\Enums\DocumentMode;
use App\Enums\TransferStatus;
use App\Enums\TransferType;
use App\Support\StatusDocument;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Usulan Mutasi & Penempatan (tabel transfers): satu SK kolektif untuk satu atau banyak karyawan.
 *
 * Alur & aturannya ada di App\Services\Transfer\TransferService.
 */
#[Fillable([
    'type', 'status', 'effective_date', 'notes',
    'basis_document_type', 'basis_document_number', 'basis_document_date', 'basis_document_file_id',
    'basis_signer_source', 'basis_signer_assignment_id', 'basis_signer_name', 'basis_signer_position',
    'decree_number', 'decree_date', 'signer_source', 'signer_assignment_id', 'signer_name', 'signer_position',
    'document_mode', 'signed_file_id', 'created_by', 'activated_at', 'cancelled_at', 'cancelled_by', 'cancel_reason',
])]
class Transfer extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'type' => TransferType::class,
            'status' => TransferStatus::class,
            'document_mode' => DocumentMode::class,
            'effective_date' => 'date',
            'basis_document_date' => 'date',
            'decree_date' => 'date',
            'activated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Karyawan di usulan, urut sesuai urutan di form. */
    public function items(): HasMany
    {
        return $this->hasMany(TransferItem::class)->orderBy('id');
    }

    public function basisDocumentFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'basis_document_file_id');
    }

    public function signedFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'signed_file_id');
    }

    public function signerAssignment(): BelongsTo
    {
        return $this->belongsTo(PositionAssignment::class, 'signer_assignment_id');
    }

    public function basisSignerAssignment(): BelongsTo
    {
        return $this->belongsTo(PositionAssignment::class, 'basis_signer_assignment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Penugasan lama yang diakhiri usulan ini saat Berlaku, beserta TMT selesai sebelumnya (untuk pembatalan). */
    public function endedAssignments(): BelongsToMany
    {
        return $this->belongsToMany(PositionAssignment::class, 'transfer_ended_assignments', 'transfer_id', 'assignment_id')
            ->withPivot('previous_end_date');
    }

    /** Perubahan atasan otomatis karena usulan ini (dan pengembaliannya saat dibatalkan). */
    public function supervisorChanges(): HasMany
    {
        return $this->hasMany(SupervisorChange::class);
    }

    /**
     * Usulan tersimpan -> bentuk isian form (lihat TransferRules), untuk diperiksa ulang
     * saat scan SK diunggah. Butuh relasi items.placementObjects & items.supervisors.
     */
    public function toPayload(): array
    {
        $date = fn ($value) => $value?->toDateString() ?? '';

        return [
            'type' => $this->type->value,
            'effective_date' => $date($this->effective_date),
            'notes' => (string) $this->notes,
            'decree_number' => (string) $this->decree_number,
            'decree_date' => $date($this->decree_date),
            // Saat diaktifkan, scan SK justru sedang diunggah, jadi dianggap jalur "Unggah".
            'document_mode' => DocumentMode::Upload->value,
            'signer' => StatusDocument::read([
                'signer_source' => $this->signer_source,
                'signer_assignment_id' => $this->signer_assignment_id,
                'signer_name' => $this->signer_name,
                'signer_position' => $this->signer_position,
            ]),
            'basis' => StatusDocument::read([
                'document_type' => $this->basis_document_type,
                'document_number' => $this->basis_document_number,
                'document_date' => $date($this->basis_document_date),
                'signer_source' => $this->basis_signer_source,
                'signer_assignment_id' => $this->basis_signer_assignment_id,
                'signer_name' => $this->basis_signer_name,
                'signer_position' => $this->basis_signer_position,
            ]),
            'items' => $this->items->map(fn (TransferItem $item) => [
                'id' => $item->id,
                'employee_id' => $item->employee_id,
                'from_assignment_id' => (string) $item->from_assignment_id,
                'position_id' => (string) $item->position_id,
                'assignment_status' => (string) $item->assignment_status,
                'sub_unit_id' => (string) $item->sub_unit_id,
                'placement_object_ids' => $item->placementObjects->pluck('id')->all(),
                'supervisor_assignment_ids' => $item->supervisors->pluck('id')->all(),
                'end_date' => $date($item->end_date),
                'notes' => (string) $item->notes,
                'restore_subordinates' => $item->restore_subordinates,
            ])->all(),
        ];
    }

    /** Sumber di Riwayat Atasan, mis. "Mutasi 2A44/P/2026.10.09-015". */
    public function supervisorChangeSource(): string
    {
        return trim('Mutasi '.$this->decree_number);
    }
}
