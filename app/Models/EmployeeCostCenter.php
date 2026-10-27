<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu catatan RIWAYAT cost center karyawan (tabel employee_cost_centers).
 * Tidak bisa diubah/dihapus; pindah cost center = catatan baru dengan "berlaku mulai" baru.
 */
#[Fillable(['employee_id', 'cost_center_id', 'effective_date', 'notes', 'created_by'])]
class EmployeeCostCenter extends Model
{
    use AppendOnly, HasUuids;

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
