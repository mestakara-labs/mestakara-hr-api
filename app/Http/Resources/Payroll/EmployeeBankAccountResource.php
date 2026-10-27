<?php

namespace App\Http\Resources\Payroll;

use App\Models\EmployeeBankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu catatan riwayat rekening bank. Controller wajib memuat relasi creator.
 *
 * @mixin EmployeeBankAccount
 */
class EmployeeBankAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            ...self::summary($this->resource),
            'notes' => $this->notes,
            /** Nama akun yang mencatat; null = sistem / akun sudah dihapus. */
            'created_by' => $this->creator?->name,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * Ringkasan rekening (dipakai juga di data karyawan & daftar skema upah):
     * { bank_name, account_number, account_holder, effective_date }, atau null.
     */
    public static function summary(?EmployeeBankAccount $account): ?array
    {
        if ($account === null) {
            return null;
        }

        return [
            'bank_name' => $account->bank_name,
            'account_number' => $account->account_number,
            'account_holder' => $account->account_holder,
            /** Berlaku mulai, format YYYY-MM-DD. */
            'effective_date' => $account->effective_date->toDateString(),
        ];
    }
}
