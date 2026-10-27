<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SK dasar struktur organisasi (tabel organization_decrees). Hanya satu yang berlaku.
 */
#[Fillable(['decree_number', 'decree_date', 'is_active', 'notes'])]
class OrganizationDecree extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'decree_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** SK yang sedang berlaku, atau null kalau belum ada. */
    public static function active(): ?self
    {
        return self::query()->where('is_active', true)->latest('decree_date')->first();
    }
}
