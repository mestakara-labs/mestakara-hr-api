<?php

namespace App\Http\Resources\Payroll;

use App\Models\EmployeeCostCenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu catatan riwayat cost center karyawan. Controller wajib memuat relasi costCenter & creator.
 *
 * @mixin EmployeeCostCenter
 */
class EmployeeCostCenterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cost_center' => self::summary($this->resource),
            'effective_date' => $this->effective_date->toDateString(),
            'notes' => $this->notes,
            /** Nama akun yang mencatat; null = sistem / akun sudah dihapus. */
            'created_by' => $this->creator?->name,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * Ringkasan cost center (dipakai juga di data karyawan & daftar skema upah):
     * { id, code, name, effective_date }, atau null. `id` = id cost center (master).
     */
    public static function summary(?EmployeeCostCenter $history): ?array
    {
        if ($history === null) {
            return null;
        }

        return [
            'id' => $history->costCenter->id,
            'code' => $history->costCenter->code,
            'name' => $history->costCenter->name,
            /** Berlaku mulai, format YYYY-MM-DD. */
            'effective_date' => $history->effective_date->toDateString(),
        ];
    }
}
