<?php

namespace App\Http\Resources\Organization;

use App\Models\Division;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bidang, sesuai DivisionResource di front end (types/api/division.ts).
 * Controller wajib memuat withCount('positions').
 *
 * @mixin Division
 */
class DivisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            /** Jumlah jabatan yang memakai bidang ini. */
            'position_count' => (int) $this->positions_count,
        ];
    }
}
