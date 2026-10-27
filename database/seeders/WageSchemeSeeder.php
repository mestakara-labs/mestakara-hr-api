<?php

namespace Database\Seeders;

use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeCostCenter;
use App\Models\EmployeeWageScheme;
use App\Models\PayrollGroup;
use App\Services\Payroll\WageSchemeRules;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data awal DATA DASAR UPAH Karyawan Casual & Daily Worker (data/wage_schemes.json), dari
 * "DU Casual Agro, Mal, Spe September 2026" (sheet per kelompok + sheet PayRoll untuk rekening):
 *
 *   skema upah   Harian; Office F&B Bosscha = Flat Bulanan (gaji tetap sebulan, upah sehari = gaji / HKE)
 *                Gaji Pokok dari Daftar Upah; Bosscha SPP PT (hanya upah per hari) = upah per hari × HKE
 *                Dibatasi 20 hari: kelompok Gunung Mas, F&B Gunung Mas, Petugas Parkir, Atta Awun, Malabar,
 *                Rancabali, Bunut — kecuali yang dibayar lebih dari 20 hari; Bosscha tidak dibatasi
 *                Tunjangan Operasional & Jabatan dan Insentif dari Daftar Upah; tanpa uang makan
 *   kelompok     sheet asal di Daftar Upah (HAFIZHAH HAURA: Malabar, tempat ia dibayar)
 *   cost center  kolom "COST CENTER"; Bosscha (tanpa kolom itu) = 4A44AGR005
 *   rekening     kolom rekening Daftar Upah / sheet PayRoll; atas nama = nama karyawan
 *
 * Baris dua nama (mis. "ASRUL ANAM/Diana Andriani"): skema & cost center untuk keduanya, rekening hanya
 * untuk orang pertama. Semua catatan berlaku mulai 1 Oktober 2026.
 *
 * Karyawan dicari dari No. Registrasi, lalu nama. Aman dijalankan berulang kali: catatan data awal ditandai
 * NOTES dan hanya catatan itu yang diganti (riwayat buatan aplikasi tidak disentuh).
 * Butuh EmployeeSeeder, CostCenterSeeder, dan PayrollGroupSeeder.
 */
class WageSchemeSeeder extends Seeder
{
    public const DATA_FILE = __DIR__.'/data/wage_schemes.json';

    /** Catatan penanda riwayat data awal. */
    public const NOTES = 'Data awal dari Daftar Upah September 2026.';

    public const EFFECTIVE_DATE = '2026-10-01';

    public function run(): void
    {
        /** @var list<array{registration_number: ?string, name: string, payroll_group: string, wage_type: string, base_salary: int, working_days: int, is_capped: bool, operational_position_allowance: int, incentive: int, cost_center_code: ?string, bank_name: ?string, account_number: ?string, account_holder: ?string}> $rows */
        $rows = json_decode(file_get_contents(self::DATA_FILE), true, flags: JSON_THROW_ON_ERROR);

        $employees = Employee::all(['id', 'registration_number', 'name', 'employment_status']);
        $byRegistration = $employees->whereNotNull('registration_number')->keyBy('registration_number');
        $byName = $employees->keyBy(fn (Employee $employee) => mb_strtoupper($employee->name));
        $groupIds = PayrollGroup::pluck('id', 'name');
        $costCenterIds = CostCenter::pluck('id', 'code');

        DB::transaction(function () use ($rows, $byRegistration, $byName, $groupIds, $costCenterIds) {
            // Riwayat tidak bisa dihapus lewat model (AppendOnly); khusus data awal, hapus lewat query lalu isi ulang.
            foreach ([EmployeeWageScheme::class, EmployeeBankAccount::class, EmployeeCostCenter::class] as $model) {
                $model::query()->where('notes', self::NOTES)->delete();
            }

            foreach ($rows as $row) {
                $employee = $byRegistration->get($row['registration_number']) ?? $byName->get(mb_strtoupper($row['name']));

                if ($employee === null || ! $employee->hasDailyWage()) {
                    $this->command?->warn("Karyawan Casual/DW tidak ditemukan, dilewati: {$row['name']}");

                    continue;
                }

                $base = [
                    'employee_id' => $employee->id,
                    'effective_date' => self::EFFECTIVE_DATE,
                    'notes' => self::NOTES,
                ];

                EmployeeWageScheme::create([
                    ...$base,
                    ...WageSchemeRules::normalize([
                        'wage_type' => $row['wage_type'],
                        'base_salary' => $row['base_salary'],
                        'working_days' => $row['working_days'],
                        'is_capped' => $row['is_capped'],
                        'operational_position_allowance' => $row['operational_position_allowance'],
                        'incentive' => $row['incentive'],
                        'has_meal_allowance' => false,
                        'payroll_group_id' => $groupIds[$row['payroll_group']],
                    ]),
                ]);

                if ($row['cost_center_code'] !== null) {
                    EmployeeCostCenter::create([...$base, 'cost_center_id' => $costCenterIds[$row['cost_center_code']]]);
                }

                if ($row['account_number'] !== null) {
                    EmployeeBankAccount::create([
                        ...$base,
                        'bank_name' => $row['bank_name'],
                        'account_number' => $row['account_number'],
                        'account_holder' => $row['account_holder'],
                    ]);
                }
            }
        });
    }
}
