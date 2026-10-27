<?php

namespace App\Models;

use App\Enums\SignatureDocument;
use App\Enums\SignatureOwnerType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Susunan penanda tangan satu pemilik (tabel signature_settings): Umum, satu Kelompok Daftar Upah, atau satu Bidang.
 * Bagian tanda tangan per dokumen ada di signature_sections; dokumen tanpa bagian = pakai susunan Umum.
 */
#[Fillable(['owner_type', 'owner_id', 'place', 'updated_by'])]
class SignatureSetting extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'owner_type' => SignatureOwnerType::class,
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(SignatureSection::class)->orderBy('sort_order');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Bagian satu dokumen, urut di dokumen (kosong = pakai susunan Umum). Butuh relasi sections.
     *
     * @return Collection<int, SignatureSection>
     */
    public function sectionsFor(SignatureDocument $document): Collection
    {
        return $this->sections->where('document', $document)->sortBy('sort_order')->values();
    }
}
