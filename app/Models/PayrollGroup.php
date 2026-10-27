<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kelompok Daftar Upah (tabel payroll_groups), mis. "Gunung Mas", "Petugas Parkir".
 * sort_order = urutan kelompok di daftar upah.
 */
#[Fillable(['name', 'sort_order', 'description'])]
class PayrollGroup extends Model
{
    use HasFactory, HasUuids;

    /** Skema upah yang memakai kelompok ini. */
    public function wageSchemes(): HasMany
    {
        return $this->hasMany(EmployeeWageScheme::class);
    }
}
