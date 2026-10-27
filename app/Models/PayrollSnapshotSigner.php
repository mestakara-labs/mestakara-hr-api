<?php

namespace App\Models;

use App\Enums\SignatureDocument;
use App\Enums\SignatureOwnerType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * POTRET satu penanda tangan yang sudah di-resolve saat periode dikunci (tabel payroll_snapshot_signers):
 * pemilik (Umum / kelompok / bidang), tempat, dokumen, bagian, nama & jabatan. inherited = memakai susunan Umum.
 */
#[Fillable([
    'period', 'owner_type', 'owner_id', 'place', 'document', 'inherited',
    'section_order', 'section_label', 'signer_order', 'name', 'position',
])]
class PayrollSnapshotSigner extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'owner_type' => SignatureOwnerType::class,
            'document' => SignatureDocument::class,
            'inherited' => 'boolean',
        ];
    }
}
