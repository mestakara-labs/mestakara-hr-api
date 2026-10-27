<?php

namespace Tests\Feature\Payroll;

use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\EmployeeCostCenter;
use App\Models\EmployeeWageScheme;
use App\Models\PayrollGroup;
use App\Models\User;
use Database\Seeders\CostCenterSeeder;
use Database\Seeders\PayrollGroupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian master Cost Center & Kelompok Daftar Upah (CRUD, tolak hapus kalau dipakai, data awal).
 */
class PayrollMasterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_seeders_fill_cost_centers_and_payroll_groups(): void
    {
        $this->seed([CostCenterSeeder::class, PayrollGroupSeeder::class]);
        $this->seed([CostCenterSeeder::class, PayrollGroupSeeder::class]);   // aman dijalankan ulang

        $this->assertSame(125, CostCenter::count());
        $this->assertSame('Tiket & Parkir', CostCenter::where('code', '4A44AGR007')->value('name'));
        $this->assertSame('Café & Resto', CostCenter::where('code', '4A44AGR005')->value('name'));

        $this->getJson('/api/v1/payroll-groups')
            ->assertOk()
            ->assertJsonCount(9, 'data')
            ->assertJsonPath('data.0.name', 'Gunung Mas')
            ->assertJsonPath('data.8.name', 'Bunut');
    }

    public function test_cost_center_crud_with_unique_code(): void
    {
        $id = $this->postJson('/api/v1/cost-centers', ['code' => '4A44AGR007', 'name' => 'Tiket & Parkir'])
            ->assertCreated()
            ->assertJsonPath('data.code', '4A44AGR007')
            ->json('data.id');

        $this->postJson('/api/v1/cost-centers', ['code' => '4A44AGR007', 'name' => 'Lain'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Kode Cost Center "4A44AGR007" sudah ada');

        $this->putJson("/api/v1/cost-centers/{$id}", ['code' => '4A44AGR007', 'name' => 'Tiket, Parkir'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Tiket, Parkir');

        $this->deleteJson("/api/v1/cost-centers/{$id}")->assertOk();
        $this->assertSame(0, CostCenter::count());
    }

    public function test_used_cost_center_cannot_be_deleted(): void
    {
        $costCenter = CostCenter::factory()->create();
        EmployeeCostCenter::create([
            'employee_id' => Employee::factory()->create()->id,
            'cost_center_id' => $costCenter->id,
            'effective_date' => '2026-10-01',
        ]);

        $this->deleteJson("/api/v1/cost-centers/{$costCenter->id}")
            ->assertConflict()
            ->assertJsonPath('success', false);
        $this->assertTrue($costCenter->exists());
    }

    public function test_payroll_group_crud_and_used_group_cannot_be_deleted(): void
    {
        $id = $this->postJson('/api/v1/payroll-groups', ['name' => 'Gunung Mas'])
            ->assertCreated()
            ->assertJsonPath('data.sort_order', 0)
            ->json('data.id');

        $this->postJson('/api/v1/payroll-groups', ['name' => 'Gunung Mas'])->assertUnprocessable();

        EmployeeWageScheme::create([
            'employee_id' => Employee::factory()->create(['employment_status' => 'Karyawan Casual'])->id,
            'wage_type' => 'Harian', 'base_salary' => 2_500_000, 'payroll_group_id' => $id, 'effective_date' => '2026-10-01',
        ]);

        $this->deleteJson("/api/v1/payroll-groups/{$id}")->assertConflict();
        $this->assertTrue(PayrollGroup::whereKey($id)->exists());
    }
}
