<?php

namespace App\Models;

use App\Enums\SignatorySource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu orang di bagian tanda tangan (tabel signature_section_signers):
 *   karyawan  nama & jabatan DIAMBIL dari data karyawan terbaru (jabatan utama aktif; tanpa jabatan = "-")
 *   manual    nama & jabatan diketik
 */
#[Fillable(['signature_section_id', 'sort_order', 'source', 'employee_id', 'name', 'position'])]
class SignatureSectionSigner extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'source' => SignatorySource::class,
            'sort_order' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
