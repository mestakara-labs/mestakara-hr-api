<?php

namespace App\Services\Payroll;

use App\Enums\ImportRowStatus;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeCostCenter;
use App\Models\EmployeeWageScheme;
use Illuminate\Support\Facades\DB;

/**
 * MENYIMPAN hasil impor Skema Upah yang sudah diperiksa WageSchemeImportChecker (tanpa baris salah).
 * Untuk tiap baris "berubah", hanya riwayat yang memang berubah yang ditambah (skema / rekening / cost center),
 * semuanya dengan Berlaku Mulai & Keterangan baris itu. Semua tersimpan dalam satu transaksi.
 */
class WageSchemeImporter
{
    /**
     * @param  list<array<string, mixed>>  $results  hasil WageSchemeImportChecker::check(), tanpa baris salah
     * @return array{employee_count: int, created: array{wage_schemes: int, bank_accounts: int, cost_centers: int}}
     */
    public function save(array $results): array
    {
        $created = ['wage_schemes' => 0, 'bank_accounts' => 0, 'cost_centers' => 0];
        $changedRows = array_filter($results, fn (array $result) => $result['status'] === ImportRowStatus::Changed->value);

        DB::transaction(function () use ($changedRows, &$created) {
            foreach ($changedRows as $result) {
                $records = $result['records'];
                $base = [
                    'employee_id' => $result['employee_id'],
                    'effective_date' => $records['effective_date'],
                    'notes' => $records['notes'],
                    'created_by' => auth()->id(),
                ];

                if ($records['wage_scheme'] !== null) {
                    EmployeeWageScheme::create([...$base, ...$records['wage_scheme']]);
                    $created['wage_schemes']++;
                }
                if ($records['bank_account'] !== null) {
                    EmployeeBankAccount::create([...$base, ...$records['bank_account']]);
                    $created['bank_accounts']++;
                }
                if ($records['cost_center_id'] !== null) {
                    EmployeeCostCenter::create([...$base, 'cost_center_id' => $records['cost_center_id']]);
                    $created['cost_centers']++;
                }
            }
        });

        return ['employee_count' => count($changedRows), 'created' => $created];
    }
}
