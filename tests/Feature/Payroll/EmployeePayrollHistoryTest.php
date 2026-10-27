<?php

namespace Tests\Feature\Payroll;

use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\PayrollGroup;
use App\Models\User;
use App\Support\EffectiveRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

/**
 * Pengujian RIWAYAT data dasar upah per karyawan: rekening, cost center, skema upah.
 * Hari ini dikunci 20 Oktober 2026.
 */
class EmployeePayrollHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 10:00:00');
        Sanctum::actingAs(User::factory()->create(['name' => 'Admin SDM']));
    }

    // -------------------------------------------------------------------------
    // Nilai yang berlaku pada tanggal X
    // -------------------------------------------------------------------------

    public function test_effective_value_is_latest_record_on_or_before_the_date(): void
    {
        $employee = Employee::factory()->create();
        $this->bankAccount($employee, '111', '2026-01-01');
        $this->bankAccount($employee, '222', '2026-09-01');
        $this->bankAccount($employee, '333', '2026-11-01');   // belum berlaku

        $employee->load('bankAccounts');
        $this->assertNull($employee->bankAccountOn(Carbon::parse('2025-12-31')));
        $this->assertSame('111', $employee->bankAccountOn(Carbon::parse('2026-08-31'))->account_number);
        $this->assertSame('222', $employee->bankAccountOn(Carbon::parse('2026-09-01'))->account_number);
        $this->assertSame('222', $employee->bankAccountOn(Carbon::parse('2026-10-20'))->account_number);
        $this->assertSame('333', $employee->bankAccountOn(Carbon::parse('2026-11-01'))->account_number);
    }

    public function test_same_effective_date_takes_the_latest_created_record(): void
    {
        $employee = Employee::factory()->create();
        $this->bankAccount($employee, '111', '2026-10-01');
        $this->travel(1)->minutes();
        $this->bankAccount($employee, '999', '2026-10-01');   // koreksi pada tanggal yang sama

        $this->assertSame('999', EffectiveRecords::on($employee->bankAccounts()->get(), Carbon::parse('2026-10-20'))->account_number);
    }

    public function test_history_records_cannot_be_changed_or_deleted(): void
    {
        $account = $this->bankAccount(Employee::factory()->create(), '111', '2026-10-01');

        try {
            $account->update(['account_number' => '222']);
            $this->fail('Riwayat seharusnya tidak bisa diubah.');
        } catch (LogicException) {
        }

        try {
            $account->delete();
            $this->fail('Riwayat seharusnya tidak bisa dihapus.');
        } catch (LogicException) {
        }

        $this->assertSame('111', $account->fresh()->account_number);
    }

    // -------------------------------------------------------------------------
    // Rekening & cost center (semua status karyawan)
    // -------------------------------------------------------------------------

    public function test_bank_account_history_newest_first_and_old_records_unchanged(): void
    {
        $employee = Employee::factory()->create();   // Karyawan Tetap juga boleh punya rekening

        $this->postJson("/api/v1/employees/{$employee->id}/bank-accounts", [
            'bank_name' => 'BRI', 'account_number' => '0123', 'account_holder' => 'ASEP', 'effective_date' => '2026-01-01',
        ])->assertCreated()->assertJsonPath('data.created_by', 'Admin SDM');

        $this->postJson("/api/v1/employees/{$employee->id}/bank-accounts", [
            'bank_name' => 'BNI', 'account_number' => '0456', 'account_holder' => 'ASEP', 'effective_date' => '2026-10-01', 'notes' => 'Pindah bank',
        ])->assertCreated();

        $this->getJson("/api/v1/employees/{$employee->id}/bank-accounts")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.bank_name', 'BNI')
            ->assertJsonPath('data.0.notes', 'Pindah bank')
            ->assertJsonPath('data.1.bank_name', 'BRI')
            ->assertJsonPath('data.1.account_number', '0123');

        $this->assertFalse(collect(app('router')->getRoutes()->getRoutes())->contains(
            fn ($route) => str_contains($route->uri(), 'bank-accounts') && in_array('DELETE', $route->methods(), true),
        ));
    }

    public function test_bank_account_number_must_be_digits(): void
    {
        $employee = Employee::factory()->create();

        $this->postJson("/api/v1/employees/{$employee->id}/bank-accounts", [
            'bank_name' => 'BRI', 'account_number' => '0123-45', 'account_holder' => 'ASEP', 'effective_date' => '01/10/2026',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.account_number.0', 'No. Rekening hanya boleh berisi angka')
            ->assertJsonPath('errors.effective_date.0', 'Berlaku Mulai harus berformat YYYY-MM-DD');
    }

    public function test_cost_center_history(): void
    {
        $employee = Employee::factory()->create();
        $ticket = CostCenter::factory()->create(['code' => '4A44AGR007', 'name' => 'Tiket & Parkir']);

        $this->postJson("/api/v1/employees/{$employee->id}/cost-centers", ['cost_center_id' => $ticket->id, 'effective_date' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('data.cost_center', ['id' => $ticket->id, 'code' => '4A44AGR007', 'name' => 'Tiket & Parkir', 'effective_date' => '2026-10-01']);

        $this->postJson("/api/v1/employees/{$employee->id}/cost-centers", ['cost_center_id' => 'bukan-id', 'effective_date' => '2026-10-01'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.cost_center_id.0', 'Cost Center tidak ditemukan');

        $this->getJson("/api/v1/employees/{$employee->id}/cost-centers")->assertOk()->assertJsonCount(1, 'data');
    }

    // -------------------------------------------------------------------------
    // Skema upah (hanya Casual & Daily Worker)
    // -------------------------------------------------------------------------

    public function test_wage_scheme_with_defaults_and_daily_rate(): void
    {
        $employee = Employee::factory()->create(['employment_status' => 'Karyawan Daily Worker', 'nik' => null]);

        $this->postJson("/api/v1/employees/{$employee->id}/wage-schemes", [
            'wage_type' => 'Harian', 'base_salary' => 2_600_000, 'is_capped' => true, 'effective_date' => '2026-10-01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.working_days', 25)
            ->assertJsonPath('data.cap_days', 20)
            ->assertJsonPath('data.daily_rate', 104_000)
            ->assertJsonPath('data.operational_position_allowance', 0)
            ->assertJsonPath('data.incentive', 0)
            ->assertJsonPath('data.has_meal_allowance', false)
            ->assertJsonPath('data.meal_allowance_per_day', 0)
            ->assertJsonPath('data.payroll_group', null);
    }

    public function test_wage_scheme_only_for_casual_and_daily_worker(): void
    {
        $permanent = Employee::factory()->create(['employment_status' => 'Karyawan Tetap']);

        $this->postJson("/api/v1/employees/{$permanent->id}/wage-schemes", [
            'wage_type' => 'Harian', 'base_salary' => 2_500_000, 'effective_date' => '2026-10-01',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.employee_id.0', 'Skema upah hanya untuk karyawan Casual & Daily Worker');
    }

    public function test_meal_allowance_yes_requires_amount_and_no_stores_zero(): void
    {
        $employee = $this->casual();
        $url = "/api/v1/employees/{$employee->id}/wage-schemes";
        $base = ['wage_type' => 'Harian', 'base_salary' => 2_500_000, 'effective_date' => '2026-10-01'];

        $this->postJson($url, [...$base, 'has_meal_allowance' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.meal_allowance_per_day.0', 'Uang Makan per Hari wajib diisi kalau Diberi Uang Makan = Ya');

        $this->postJson($url, [...$base, 'has_meal_allowance' => true, 'meal_allowance_per_day' => 0])
            ->assertUnprocessable()
            ->assertJsonPath('errors.meal_allowance_per_day.0', 'Uang Makan per Hari harus lebih dari 0');

        $this->postJson($url, [...$base, 'has_meal_allowance' => false, 'meal_allowance_per_day' => 15_000])
            ->assertCreated()
            ->assertJsonPath('data.meal_allowance_per_day', 0);
    }

    public function test_wage_scheme_validates_amounts_and_accepts_empty_payroll_group(): void
    {
        $employee = $this->casual();
        $url = "/api/v1/employees/{$employee->id}/wage-schemes";

        $this->postJson($url, ['wage_type' => 'Mingguan', 'base_salary' => 0, 'working_days' => 32, 'effective_date' => '2026-10-01'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.wage_type.0', 'Jenis Upah harus Harian atau Flat Bulanan')
            ->assertJsonPath('errors.base_salary.0', 'Gaji Pokok harus lebih dari 0')
            ->assertJsonPath('errors.working_days.0', 'HKE harus 1–31');

        $group = PayrollGroup::factory()->create(['name' => 'Gunung Mas']);
        $this->postJson($url, ['wage_type' => 'Flat Bulanan', 'base_salary' => 3_000_000, 'payroll_group_id' => $group->id, 'effective_date' => '2026-09-01'])
            ->assertCreated()
            ->assertJsonPath('data.payroll_group.name', 'Gunung Mas');
        $this->postJson($url, ['wage_type' => 'Flat Bulanan', 'base_salary' => 3_000_000, 'payroll_group_id' => null, 'effective_date' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('data.payroll_group', null);

        $this->getJson("{$url}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.effective_date', '2026-10-01')
            ->assertJsonPath('data.1.payroll_group.name', 'Gunung Mas');
    }

    // -------------------------------------------------------------------------
    // Data karyawan (EmployeeProfileResource)
    // -------------------------------------------------------------------------

    public function test_employee_profile_shows_bank_account_and_cost_center_effective_on_reference_date(): void
    {
        $employee = Employee::factory()->create();
        $this->bankAccount($employee, '111', '2026-01-01');
        $this->bankAccount($employee, '222', '2026-10-01');
        $costCenter = CostCenter::factory()->create(['code' => '4A44AGR007', 'name' => 'Tiket & Parkir']);
        $employee->costCenterHistories()->create(['cost_center_id' => $costCenter->id, 'effective_date' => '2026-10-01']);

        $this->getJson("/api/v1/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.bank_account.account_number', '222')
            ->assertJsonPath('data.cost_center.code', '4A44AGR007');

        $september = collect($this->getJson('/api/v1/employees?period=2026-09')->json('data'))->firstWhere('id', $employee->id);
        $this->assertSame('111', $september['bank_account']['account_number']);
        $this->assertNull($september['cost_center']);
    }

    private function casual(): Employee
    {
        return Employee::factory()->create(['employment_status' => 'Karyawan Casual', 'nik' => null]);
    }

    private function bankAccount(Employee $employee, string $number, string $effectiveDate): EmployeeBankAccount
    {
        return $employee->bankAccounts()->create([
            'bank_name' => 'BRI', 'account_number' => $number, 'account_holder' => $employee->name, 'effective_date' => $effectiveDate,
        ]);
    }
}
