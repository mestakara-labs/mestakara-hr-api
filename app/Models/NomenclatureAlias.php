<?php

namespace App\Models;

use App\Enums\AliasStatus;
use App\Enums\NomenclatureType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kamus nomenklatur (tabel nomenclature_aliases): satu penulisan ASLI dan padanan standarnya.
 *
 *   type Jabatan           "Kary. Cleaning Service" -> position          "Pelaksana Cleaning Service"
 *   type Objek Penempatan  "PARKIR"                 -> placementObject   "Parkir"
 *   type Sub Unit          "AFD.GUNUNG MAS"         -> subUnit           "Afdeling Gunung Mas"
 *
 * Padanan boleh kosong (status Belum Dipetakan), mis. nama lokasi umum seperti "AGRO GUM".
 */
#[Fillable(['type', 'alias', 'position_id', 'placement_object_id', 'sub_unit_id', 'status', 'notes'])]
class NomenclatureAlias extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'type' => NomenclatureType::class,
            'status' => AliasStatus::class,
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function placementObject(): BelongsTo
    {
        return $this->belongsTo(PlacementObject::class);
    }

    public function subUnit(): BelongsTo
    {
        return $this->belongsTo(SubUnit::class);
    }
}
