<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bidang (tabel divisions): kelompok pekerjaan yang dipimpin Asisten Manager (BRM-2).
 */
#[Fillable(['code', 'name', 'description'])]
class Division extends Model
{
    use HasFactory, HasUuids;

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }
}
