<?php

namespace App\Http\Resources\Payroll;

use App\Models\EmployeeWageScheme;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu catatan riwayat skema upah. Controller wajib memuat relasi payrollGroup & creator.
 * Nominal dalam rupiah (bilangan bulat).
 *
 * @mixin EmployeeWageScheme
 */
class WageSchemeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** Harian / Flat Bulanan. */
            'wage_type' => $this->wage_type->value,
            'base_salary' => $this->base_salary,
            /** HKE. */
            'working_days' => $this->working_days,
            /** Upah sehari = Gaji Pokok / HKE, dibulatkan. */
            'daily_rate' => $this->dailyRate(),
            'is_capped' => $this->is_capped,
            /** Batas hari; null kalau tidak dibatasi. */
            'cap_days' => $this->cap_days,
            'operational_position_allowance' => $this->operational_position_allowance,
            'incentive' => $this->incentive,
            'has_meal_allowance' => $this->has_meal_allowance,
            /** 0 kalau tidak diberi uang makan. */
            'meal_allowance_per_day' => $this->meal_allowance_per_day,
            /** { id, name }, atau null kalau belum masuk kelompok. */
            'payroll_group' => $this->payrollGroup === null ? null : [
                'id' => $this->payrollGroup->id,
                'name' => $this->payrollGroup->name,
            ],
            /** Berlaku mulai, format YYYY-MM-DD. */
            'effective_date' => $this->effective_date->toDateString(),
            'notes' => $this->notes,
            /** Nama akun yang mencatat; null = sistem / akun sudah dihapus. */
            'created_by' => $this->creator?->name,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
