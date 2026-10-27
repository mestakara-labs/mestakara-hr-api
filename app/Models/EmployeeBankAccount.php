<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu catatan RIWAYAT rekening bank karyawan (tabel employee_bank_accounts).
 * Tidak bisa diubah/dihapus; rekening baru = catatan baru dengan "berlaku mulai" baru.
 */
#[Fillable(['employee_id', 'bank_name', 'account_number', 'account_holder', 'effective_date', 'notes', 'created_by'])]
class EmployeeBankAccount extends Model
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
