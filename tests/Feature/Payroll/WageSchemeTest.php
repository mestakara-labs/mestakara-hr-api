<?php

namespace Tests\Feature\Payroll;

use App\Enums\DocumentScope;
use App\Enums\ProposalStatus;
use App\Models\Employee;
use App\Models\EmployeeTermination;
use App\Models\EmployeeWageScheme;
use App\Models\PayrollGroup;
use App\Models\PlacementObject;
use App\Models\PositionAssignment;
use App\Models\SubUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian halaman Skema Upah: daftar per periode (GET /wage-schemes) & ubah massal (POST /wage-schemes/bulk).
 * Hari ini dikunci 20 Oktober 2026.
 */
class WageSchemeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 10:00:00');
        Sanctum::actingAs(User::factory()->create());
    }

    // -------------------------------------------------------------------------
    // Daftar per periode
    // -------------------------------------------------------------------------

    public function test_lists_only_active_casual_and_daily_workers(): void
    {
        $casual = $this->dailyWorker('Ani', 'Karyawan Casual');
        $dailyWorker = $this->dailyWorker('Budi', 'Karyawan Daily Worker');
        Employee::factory()->active()->create(['name' => 'Cici']);                                   // Tetap
        Employee::factory()->create(['name' => 'Dodi', 'employment_status' => 'Karyawan Casual']);  // Belum Aktif

        $this->getJson('/api/v1/wage-schemes')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $casual->id)
            ->assertJsonPath('data.1.id', $dailyWorker->id)
            ->assertJsonPath('data.0.wage_scheme', null)        // "Belum diatur"
            ->assertJsonPath('data.0.daily_rate', null);
    }

    public function test_period_uses_status_and_scheme_on_reference_date(): void
    {
        $employee = $this->dailyWorker('Ani');
        $this->scheme($employee, ['base_salary' => 2_500_000, 'effective_date' => '2026-01-01']);
        $this->scheme($employee, ['base_salary' => 2_600_000, 'working_days' => 26, 'effective_date' => '2026-10-01']);
        $this->terminate($employee, '2026-10-05');

        // September: masih aktif, skema lama berlaku.
        $this->getJson('/api/v1/wage-schemes?period=2026-09')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.wage_scheme.base_salary', 2_500_000)
            ->assertJsonPath('data.0.daily_rate', 100_000);

        // Oktober (acuan hari ini): sudah nonaktif sejak 5 Oktober.
        $this->getJson('/api/v1/wage-schemes?period=2026-10')->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/wage-schemes?period=2026-12')
            ->assertUnprocessable()
            ->assertJsonPath('errors.period.0', 'Periode Data tidak boleh melewati bulan berjalan');
    }

    public function test_daily_rate_is_rounded_and_placement_comes_from_main_assignment(): void
    {
        $employee = $this->dailyWorker('Ani');
        $this->scheme($employee, ['base_salary' => 2_500_000, 'working_days' => 26]);   // 96.153,85
        $subUnit = SubUnit::factory()->create(['name' => 'Afdeling Gunung Mas', 'afdeling_code' => 'AFD50']);
        $assignment = PositionAssignment::factory()->for($employee)->create(['sub_unit_id' => $subUnit->id, 'start_date' => '2026-01-01']);
        $assignment->placementObjects()->attach(PlacementObject::factory()->create(['name' => 'Parkir'])->id);

        $this->getJson('/api/v1/wage-schemes')
            ->assertOk()
            ->assertJsonPath('data.0.daily_rate', 96_154)
            ->assertJsonPath('data.0.sub_unit.afdeling_code', 'AFD50')
            ->assertJsonPath('data.0.placement_objects.0.name', 'Parkir');
    }

    // -------------------------------------------------------------------------
    // Ubah massal
    // -------------------------------------------------------------------------

    public function test_bulk_changes_only_sent_fields_and_keeps_old_records(): void
    {
        $group = PayrollGroup::factory()->create();
        $ani = $this->dailyWorker('Ani');
        $budi = $this->dailyWorker('Budi');
        $old = $this->scheme($ani, ['base_salary' => 2_500_000, 'incentive' => 50_000, 'has_meal_allowance' => true, 'meal_allowance_per_day' => 15_000, 'payroll_group_id' => $group->id]);
        $this->scheme($budi, ['base_salary' => 2_400_000, 'wage_type' => 'Flat Bulanan']);

        $this->postJson('/api/v1/wage-schemes/bulk', [
            'employee_ids' => [$ani->id, $budi->id],
            'effective_date' => '2026-10-01',
            'fields' => ['base_salary' => 2_700_000],
            'notes' => 'Kenaikan UMK',
        ])->assertOk()->assertJsonPath('data.employee_count', 2);

        $newAni = $ani->wageSchemes()->where('effective_date', '>=', '2026-10-01')->sole();
        $this->assertSame(2_700_000, $newAni->base_salary);
        $this->assertSame(50_000, $newAni->incentive);
        $this->assertSame(15_000, $newAni->meal_allowance_per_day);
        $this->assertSame($group->id, $newAni->payroll_group_id);
        $this->assertSame('Kenaikan UMK', $newAni->notes);
        $this->assertSame('Flat Bulanan', $budi->wageSchemes()->latest('effective_date')->first()->wage_type->value);
        $this->assertSame(2_500_000, $old->fresh()->base_salary);   // catatan lama tidak berubah
    }

    public function test_bulk_can_clear_payroll_group_and_meal_rules_follow_old_scheme(): void
    {
        $withMeal = $this->dailyWorker('Ani');
        $withoutMeal = $this->dailyWorker('Budi');
        $this->scheme($withMeal, ['has_meal_allowance' => true, 'meal_allowance_per_day' => 15_000, 'payroll_group_id' => PayrollGroup::factory()->create()->id]);
        $this->scheme($withoutMeal);

        // Skema lama Ani sudah punya nominal > 0: cukup kirim has_meal_allowance.
        $this->postJson('/api/v1/wage-schemes/bulk', [
            'employee_ids' => [$withMeal->id], 'effective_date' => '2026-10-01',
            'fields' => ['has_meal_allowance' => true, 'payroll_group_id' => null],
        ])->assertOk();
        $latest = $withMeal->wageSchemes()->latest('effective_date')->first();
        $this->assertNull($latest->payroll_group_id);
        $this->assertSame(15_000, $latest->meal_allowance_per_day);

        // Budi belum pernah diberi uang makan: nominal wajib dikirim.
        $errors = $this->postJson('/api/v1/wage-schemes/bulk', [
            'employee_ids' => [$withoutMeal->id], 'effective_date' => '2026-10-01',
            'fields' => ['has_meal_allowance' => true],
        ])->assertUnprocessable()->json('errors');
        $this->assertSame(['Budi: Uang Makan per Hari wajib diisi kalau Diberi Uang Makan = Ya'], $errors["employees.{$withoutMeal->id}"]);
    }

    public function test_bulk_rejects_everything_when_one_employee_has_no_complete_scheme(): void
    {
        $withScheme = $this->dailyWorker('Ani');
        $withoutScheme = $this->dailyWorker('Budi');
        $permanent = Employee::factory()->active()->create(['name' => 'Cici']);
        $this->scheme($withScheme);

        $errors = $this->postJson('/api/v1/wage-schemes/bulk', [
            'employee_ids' => [$withScheme->id, $withoutScheme->id, $permanent->id],
            'effective_date' => '2026-10-01',
            'fields' => ['incentive' => 100_000],
        ])->assertUnprocessable()->json('errors');

        $this->assertSame(['Budi: Jenis Upah wajib diisi', 'Budi: Gaji Pokok wajib diisi'], $errors["employees.{$withoutScheme->id}"]);
        $this->assertSame(['Cici: Skema upah hanya untuk karyawan Casual & Daily Worker'], $errors["employees.{$permanent->id}"]);
        $this->assertArrayNotHasKey("employees.{$withScheme->id}", $errors);

        $this->assertSame(1, EmployeeWageScheme::count());   // tidak ada yang disimpan

        // Dengan isian lengkap, karyawan tanpa skema bisa diatur sekaligus.
        $this->postJson('/api/v1/wage-schemes/bulk', [
            'employee_ids' => [$withScheme->id, $withoutScheme->id],
            'effective_date' => '2026-10-01',
            'fields' => ['wage_type' => 'Harian', 'base_salary' => 2_600_000],
        ])->assertOk()->assertJsonPath('data.employee_count', 2);
    }

    public function test_bulk_requires_at_least_one_known_field(): void
    {
        $employee = $this->dailyWorker('Ani');

        $this->postJson('/api/v1/wage-schemes/bulk', ['employee_ids' => [$employee->id], 'effective_date' => '2026-10-01', 'fields' => []])
            ->assertUnprocessable()
            ->assertJsonPath('errors.fields.0', 'Pilih minimal satu isian yang diubah');

        $this->postJson('/api/v1/wage-schemes/bulk', ['employee_ids' => [$employee->id], 'effective_date' => '2026-10-01', 'fields' => ['gaji' => 1]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.fields.0', 'Isian yang diubah tidak dikenal');
    }

    private function dailyWorker(string $name, string $status = 'Karyawan Casual'): Employee
    {
        return Employee::factory()->active()->create(['name' => $name, 'employment_status' => $status, 'nik' => null]);
    }

    private function scheme(Employee $employee, array $values = []): EmployeeWageScheme
    {
        return EmployeeWageScheme::create([
            'employee_id' => $employee->id,
            'wage_type' => 'Harian',
            'base_salary' => 2_500_000,
            'working_days' => 25,
            'effective_date' => '2026-01-01',
            ...$values,
        ]);
    }

    private function terminate(Employee $employee, string $effectiveDate): void
    {
        $termination = EmployeeTermination::create([
            'status' => ProposalStatus::Effective,
            'document_scope' => DocumentScope::Collective,
            'created_by' => User::factory()->create()->id,
            'activated_at' => now(),
        ]);
        $termination->items()->create(['employee_id' => $employee->id, 'type' => 'Mengundurkan Diri', 'effective_date' => $effectiveDate]);
    }
}
