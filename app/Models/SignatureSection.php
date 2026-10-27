<?php

namespace App\Models;

use App\Enums\SignatureDocument;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu bagian tanda tangan (tabel signature_sections), mis. "Diperiksa Oleh", berisi satu atau lebih orang.
 */
#[Fillable(['signature_setting_id', 'document', 'sort_order', 'label'])]
class SignatureSection extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'document' => SignatureDocument::class,
            'sort_order' => 'integer',
        ];
    }

    public function signers(): HasMany
    {
        return $this->hasMany(SignatureSectionSigner::class)->orderBy('sort_order');
    }
}
