<?php

namespace App\Http\Resources\Payroll;

use App\Models\PayrollGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kelompok Daftar Upah: { id, name, sort_order, description }.
 *
 * @mixin PayrollGroup
 */
class PayrollGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            /** Urutan di daftar upah. */
            'sort_order' => $this->sort_order,
            'description' => $this->description,
        ];
    }
}
