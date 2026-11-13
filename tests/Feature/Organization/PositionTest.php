<?php

namespace Tests\Feature\Organization;

use App\Models\Division;
use App\Models\Employee;
use App\Models\OrganizationDecree;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian master Jabatan (/positions) dan bagan organisasi (/organization).
 */
class PositionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_create_manager_without_division(): void
    {
        $this->postJson('/api/v1/positions', [
            'code' => 'JAB-001', 'title' => 'Manager', 'brm_level' => 1,
            'job_grade' => null, 'division_id' => '', 'description' => '',
        ])
            ->assertCreated()
            ->assertJsonPath('data.division_id', null)
            ->assertJsonPath('data.division_name', null)
            ->assertJsonPath('data.employees', []);
    }

    public function test_brm_2_and_below_requires_division(): void
    {
        $this->postJson('/api/v1/positions', [
            'code' => 'JAB-002', 'title' => 'Asisten Manager', 'brm_level' => 2,
            'job_grade' => null, 'division_id' => '', 'description' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.division_id.0', 'Pilih Bidang');
    }

    public function test_invalid_brm_level_and_job_grade_are_rejected(): void
    {
        $this->postJson('/api/v1/positions', [
            'code' => 'JAB-003', 'title' => 'X', 'brm_level' => null,
            'job_grade' => 100, 'division_id' => '', 'description' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.brm_level.0', 'Pilih Level BRM')
            ->assertJsonPath('errors.job_grade.0', 'Job Grade harus angka bulat 1–99');
    }

    public function test_create_position_with_division_and_job_grade(): void
    {
        $division = Division::factory()->create(['name' => 'Umum dan Legal']);

        $this->postJson('/api/v1/positions', [
            'code' => 'JAB-015', 'title' => 'Asisten Personalia', 'brm_level' => 3,
            'job_grade' => 12, 'division_id' => $division->id, 'description' => '',
        ])
            ->assertCreated()
            ->assertJsonPath('data.division_name', 'Umum dan Legal')
            ->assertJsonPath('data.job_grade', 12);
    }

    public function test_organization_returns_active_decree_and_positions(): void
    {
        OrganizationDecree::create(['decree_number' => 'KEP/IV.2/1667/XI/2023', 'decree_date' => '2023-11-01', 'is_active' => true]);
        Position::factory()->create();
        Position::factory()->manager()->create();

        $this->getJson('/api/v1/organization')
            ->assertOk()
            ->assertJsonPath('data.decree_number', 'KEP/IV.2/1667/XI/2023')
            ->assertJsonPath('data.decree_date', '2023-11-01')
            ->assertJsonPath('data.positions.0.brm_level', 1)
            ->assertJsonCount(2, 'data.positions');
    }

    public function test_organization_without_decree_returns_empty_strings(): void
    {
        $this->getJson('/api/v1/organization')
            ->assertOk()
            ->assertJsonPath('data.decree_number', '')
            ->assertJsonPath('data.positions', []);
    }

    public function test_holder_whatsapp_is_empty_when_not_filled_instead_of_falling_back_to_phone(): void
    {
        $position = Position::factory()->create();
        $withoutWhatsapp = Employee::factory()->create(['phone' => '081234567890', 'whatsapp' => null]);
        $withWhatsapp = Employee::factory()->create(['phone' => '081234567890', 'whatsapp' => '089876543210']);
        PositionAssignment::factory()->for($position)->for($withoutWhatsapp)->create();
        PositionAssignment::factory()->for($position)->for($withWhatsapp)->create();

        $employees = collect($this->getJson("/api/v1/positions/{$position->id}")->assertOk()->json('data.employees'));

        $this->assertSame('', $employees->firstWhere('id', $withoutWhatsapp->id)['whatsapp']);
        $this->assertSame('089876543210', $employees->firstWhere('id', $withWhatsapp->id)['whatsapp']);
    }

    public function test_requests_without_token_are_rejected(): void
    {
        $this->app['auth']->forgetGuards();

        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/positions')->assertUnauthorized();
    }
}
