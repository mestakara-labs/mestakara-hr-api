<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeCostCenter;
use App\Models\EmployeeWageScheme;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\WageSchemeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pengujian data awal skema upah, rekening, & cost center karyawan Casual & DW
 * (WageSchemeSeeder, Daftar Upah September 2026, berlaku 1 Oktober 2026).
 */
class WageSchemeSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_every_row_gets_a_scheme_cost_center_and_most_a_bank_account(): void
    {
        $rows = json_decode(file_get_contents(WageSchemeSeeder::DATA_FILE), true);

        $this->assertSame(count($rows), EmployeeWageScheme::count());
        $this->assertSame(count($rows), EmployeeCostCenter::count());
        $this->assertSame(count(array_filter($rows, fn (array $row) => $row['account_number'] !== null)), EmployeeBankAccount::count());
        $this->assertSame(count($rows), EmployeeWageScheme::whereDate('effective_date', '2026-10-01')->count());
    }

    public function test_daily_wage_capped_at_twenty_days_with_cost_center_and_bank_account(): void
    {
        $employee = $this->employee('7761');   // A. ROHMAN, Gunung Mas
        $scheme = $employee->wageSchemeOn(Carbon::parse('2026-10-01'));

        $this->assertSame('Harian', $scheme->wage_type->value);
        $this->assertSame(2_375_000, $scheme->base_salary);
        $this->assertSame(95_000, $scheme->dailyRate());
        $this->assertTrue($scheme->is_capped);
        $this->assertSame(20, $scheme->cap_days);
        $this->assertFalse($scheme->has_meal_allowance);
        $this->assertSame('Gunung Mas', $scheme->payrollGroup->name);
        $this->assertSame('4A44AGR007', $employee->costCenterOn(Carbon::parse('2026-10-01'))->costCenter->code);
        $this->assertSame('224501022639508', $employee->bankAccountOn(Carbon::parse('2026-10-01'))->account_number);

        // Belum berlaku di bulan September.
        $this->assertNull($employee->wageSchemeOn(Carbon::parse('2026-09-30')));
    }

    public function test_bosscha_rules(): void
    {
        // Office F&B: gaji tetap sebulan.
        $office = $this->scheme('833538');   // ANDRIAN
        $this->assertSame('Flat Bulanan', $office->wage_type->value);
        $this->assertSame(3_500_000, $office->base_salary);
        $this->assertFalse($office->is_capped);

        // Bosscha Staff: tunjangan operasional & jabatan dan insentif ikut; tidak dibatasi 20 hari.
        $staff = $this->scheme('548502');    // MUHAMMAD ARIES GUMILANG
        $this->assertSame(1_250_000, $staff->operational_position_allowance);
        $this->assertSame(750_000, $staff->incentive);
        $this->assertFalse($staff->is_capped);

        // Bosscha SPP PT: hanya upah per hari -> gaji pokok = upah per hari × 25.
        $spp = $this->scheme('779504');      // SRI RAHAYU
        $this->assertSame(2_500_000, $spp->base_salary);
        $this->assertSame(100_000, $spp->dailyRate());
        $this->assertSame('4A44AGR005', $this->employee('779504')->costCenterHistories()->sole()->costCenter->code);
    }

    public function test_special_rows(): void
    {
        // Dibayar lebih dari 20 hari -> tidak dibatasi.
        $this->assertFalse($this->scheme('442')->is_capped);   // BUDI SALAM

        // HAFIZHAH HAURA tercatat di dua sheet; dipakai Malabar (tempat ia dibayar), satu skema saja.
        $this->assertSame('Malabar', $this->scheme('760649')->payrollGroup->name);
        $this->assertSame(1, $this->employee('760649')->wageSchemes()->count());

        // Baris dua nama: skema untuk keduanya, rekening hanya orang pertama.
        $diana = Employee::where('name', 'DIANA ANDRIANI')->sole();
        $this->assertSame(825_000, $diana->wageSchemes()->sole()->operational_position_allowance);
        $this->assertSame(0, $diana->bankAccounts()->count());
        $this->assertSame(1, $this->employee('819535')->bankAccounts()->count());   // ASRUL ANAM
    }

    public function test_running_twice_replaces_only_the_seeded_records(): void
    {
        $employee = $this->employee('7761');
        $employee->bankAccounts()->create([
            'bank_name' => 'BNI', 'account_number' => '0123', 'account_holder' => 'A. ROHMAN', 'effective_date' => '2026-11-01',
        ]);
        $count = EmployeeWageScheme::count();

        $this->seed(WageSchemeSeeder::class);

        $this->assertSame($count, EmployeeWageScheme::count());
        $this->assertSame(2, $employee->bankAccounts()->count());   // catatan buatan aplikasi tetap ada
    }

    private function employee(string $registrationNumber): Employee
    {
        return Employee::where('registration_number', $registrationNumber)->sole();
    }

    private function scheme(string $registrationNumber): EmployeeWageScheme
    {
        return $this->employee($registrationNumber)->wageSchemes()->with('payrollGroup')->sole();
    }
}
