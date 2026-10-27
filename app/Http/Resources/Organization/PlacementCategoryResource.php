<?php

namespace App\Http\Resources\Organization;

use App\Models\PlacementCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kategori objek, sesuai PlacementCategoryResource di front end (types/api/placement-category.ts).
 * Controller wajib memuat withCount('placementObjects').
 *
 * @mixin PlacementCategory
 */
class PlacementCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            /** Jumlah objek penempatan di kategori ini. */
            'object_count' => (int) $this->placement_objects_count,
        ];
    }
}
