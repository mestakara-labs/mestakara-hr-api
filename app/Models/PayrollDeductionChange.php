<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Jejak perubahan potongan (tabel payroll_deduction_changes): satu isian, nilai lama -> baru, sumber
 * ("ubah" = diisi admin, "salin" = disalin dari periode sebelumnya). Tidak pernah diubah/dihapus.
 */
#[Fillable(['period', 'employee_id', 'field', 'old_value', 'new_value', 'source', 'changed_by'])]
class PayrollDeductionChange extends Model
{
    use AppendOnly, HasUuids;

    public const UPDATED_AT = null;
}
