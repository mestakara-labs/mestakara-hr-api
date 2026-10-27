<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cost center SAP (tabel cost_centers), mis. 4A44AGR007 "Tiket & Parkir".
 */
#[Fillable(['code', 'name', 'description'])]
class CostCenter extends Model
{
    use HasFactory, HasUuids;

    /** Riwayat cost center karyawan yang memakai cost center ini. */
    public function employeeCostCenters(): HasMany
    {
        return $this->hasMany(EmployeeCostCenter::class);
    }
}
