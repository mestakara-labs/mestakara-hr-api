<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu anggota batih / keluarga inti karyawan (tabel employee_family_members): suami, istri, atau anak.
 */
#[Fillable([
    'employee_id', 'relationship', 'name', 'ktp_number', 'gender', 'birth_place', 'birth_date',
    'occupation', 'dependent_status',
])]
class EmployeeFamilyMember extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
