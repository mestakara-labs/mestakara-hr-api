<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Potongan manual satu karyawan pada satu periode upah (tabel payroll_deductions):
 * BPJS TK/KES, PPIP/PPh Ps.21, Kebun/Unit. Tanpa baris = semua 0. Perubahannya dicatat di payroll_deduction_changes.
 */
#[Fillable(['period', 'employee_id', 'bpjs', 'pph21', 'unit', 'updated_by'])]
class PayrollDeduction extends Model
{
    use HasUuids;

    /** Isian potongan. */
    public const FIELDS = ['bpjs', 'pph21', 'unit'];

    protected function casts(): array
    {
        return [
            'bpjs' => 'integer',
            'pph21' => 'integer',
            'unit' => 'integer',
        ];
    }

    public function total(): int
    {
        return $this->bpjs + $this->pph21 + $this->unit;
    }
}
