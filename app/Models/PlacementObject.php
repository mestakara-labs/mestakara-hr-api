<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Objek penempatan (tabel placement_objects), mis. "Tea Bridge".
 * Satu objek bisa tersedia di beberapa sub unit (pivot placement_object_sub_units).
 */
#[Fillable(['category_id', 'name', 'description'])]
class PlacementObject extends Model
{
    use HasFactory, HasUuids;

    public function category(): BelongsTo
    {
        return $this->belongsTo(PlacementCategory::class, 'category_id');
    }

    /** Sub unit tempat objek ini tersedia, urut sesuai urutan master Sub Unit (UUID v7 = urut waktu dibuat). */
    public function subUnits(): BelongsToMany
    {
        return $this->belongsToMany(SubUnit::class, 'placement_object_sub_units')->orderBy('sub_units.id');
    }

    /** Semua penugasan jabatan di objek ini (aktif maupun riwayat). */
    public function assignments(): BelongsToMany
    {
        return $this->belongsToMany(PositionAssignment::class, 'assignment_placement_objects', 'placement_object_id', 'assignment_id');
    }
}
