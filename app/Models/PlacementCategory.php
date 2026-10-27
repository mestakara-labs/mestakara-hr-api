<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kategori objek penempatan (tabel placement_categories), mis. "Penginapan", "Wahana".
 */
#[Fillable(['name', 'description'])]
class PlacementCategory extends Model
{
    use HasFactory, HasUuids;

    public function placementObjects(): HasMany
    {
        return $this->hasMany(PlacementObject::class, 'category_id');
    }
}
