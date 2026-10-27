<?php

namespace App\Models\Concerns;

use App\Models\File;
use App\Models\PositionAssignment;
use App\Services\FileStorage;
use App\Support\StatusDocument;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Untuk model yang menyimpan SATU dokumen dasar + penandatangan (kolom document_* & signer_*):
 * usulan Penambahan / Penonaktifan (dokumen kolektif) dan itemnya (dokumen per orang).
 *
 * Model pemakainya wajib meng-cast 'document_date' => 'date'.
 */
trait HasStatusDocument
{
    public function documentFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'document_file_id');
    }

    public function signerAssignment(): BelongsTo
    {
        return $this->belongsTo(PositionAssignment::class, 'signer_assignment_id');
    }

    /** Sudah ada file dokumennya? */
    public function hasDocumentFile(): bool
    {
        return $this->document_file_id !== null;
    }

    /** Isian dokumen tersimpan dalam bentuk teks (untuk diperiksa ulang). */
    public function documentValues(): array
    {
        return StatusDocument::read([
            'document_type' => $this->document_type,
            'document_number' => $this->document_number,
            'document_date' => $this->document_date?->toDateString(),
            'signer_source' => $this->signer_source,
            'signer_assignment_id' => $this->signer_assignment_id,
            'signer_name' => $this->signer_name,
            'signer_position' => $this->signer_position,
        ]);
    }

    /**
     * Dokumen untuk front end (StatusDocumentResource): { type, number, date, file, file_url, signer }.
     * null kalau belum diisi sama sekali. Butuh relasi documentFile & signerAssignment.employee/position.
     */
    public function documentResource(string $fileUrl): ?array
    {
        if ($this->document_type === null && $this->document_file_id === null) {
            return null;
        }

        return [
            'type' => $this->document_type ?? '',
            'number' => $this->document_number ?? '',
            'date' => $this->document_date?->toDateString() ?? '',
            'file' => FileStorage::info($this->documentFile),
            'file_url' => $this->document_file_id ? $fileUrl : null,
            'signer' => StatusDocument::signerResource($this->signer_source, $this->signerAssignment, $this->signer_name, $this->signer_position),
        ];
    }
}
