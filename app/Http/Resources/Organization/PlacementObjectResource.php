<?php

namespace App\Http\Resources\Organization;

use App\Models\PlacementObject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Objek penempatan, sesuai PlacementObjectResource di front end (types/api/placement-object.ts).
 * Controller wajib memuat relasi category & subUnits dan jumlah active_assignments_count.
 *
 * @mixin PlacementObject
 */
class PlacementObjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category_id' => $this->category_id,
            'category_name' => $this->category->name,
            /** Sub unit tempat objek ini tersedia (minimal satu). */
            'sub_units' => $this->subUnits->map(fn ($subUnit) => [
                'id' => $subUnit->id,
                'name' => $subUnit->name,
            ]),
            'description' => $this->description,
            /** Jumlah penugasan jabatan AKTIF di objek ini. */
            'assignment_count' => (int) $this->active_assignments_count,
        ];
    }
}
