<?php

namespace App\Http\Resources\Organization;

use App\Models\SubUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sub unit, sesuai SubUnitResource di front end (types/api/sub-unit.ts).
 * Controller wajib memuat jumlah placement_objects_count & active_assignments_count (lihat SubUnitController::counts).
 *
 * @mixin SubUnit
 */
class SubUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'afdeling_code' => $this->afdeling_code,
            'description' => $this->description,
            /** Jumlah penugasan jabatan AKTIF di sub unit ini. */
            'assignment_count' => (int) $this->active_assignments_count,
            /** Jumlah objek penempatan yang tersedia di sub unit ini. */
            'object_count' => (int) $this->placement_objects_count,
        ];
    }
}
