<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sub Unit / afdeling (tabel sub_units), mis. "Agrowisata Gunung Mas" (AFD03).
 */
#[Fillable(['name', 'afdeling_code', 'description'])]
class SubUnit extends Model
{
    use HasFactory, HasUuids;

    /** Objek penempatan yang tersedia di sub unit ini. */
    public function placementObjects(): BelongsToMany
    {
        return $this->belongsToMany(PlacementObject::class, 'placement_object_sub_units');
    }

    /** Semua penugasan jabatan di sub unit ini (aktif maupun riwayat). */
    public function assignments(): HasMany
    {
        return $this->hasMany(PositionAssignment::class);
    }
}
