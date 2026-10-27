<?php

namespace Tests\Feature\Assignment;

use App\Models\Employee;
use App\Models\PlacementObject;
use App\Models\PositionAssignment;
use App\Models\SubUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian Jabatan Karyawan (/position-assignments) dan dampaknya ke master & karyawan.
 */
class PositionAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_list_shows_period_and_newest_first(): void
    {
        $finished = PositionAssignment::factory()->finished()->create();
        $active = PositionAssignment::factory()->create();
        $upcoming = PositionAssignment::factory()->upcoming()->create();

        $this->getJson('/api/v1/position-assignments')
            ->assertOk()
            ->assertJsonPath('data.0.id', $upcoming->id)
            ->assertJsonPath('data.0.period', 'Akan Datang')
            ->assertJsonPath('data.1.id', $active->id)
            ->assertJsonPath('data.1.is_active', true)
            ->assertJsonPath('data.2.id', $finished->id)
            ->assertJsonPath('data.2.period', 'Selesai');
    }

    public function test_list_can_be_filtered_by_employee(): void
    {
        $assignment = PositionAssignment::factory()->create();
        PositionAssignment::factory()->create();

        $this->getJson("/api/v1/position-assignments?employee_id={$assignment->employee_id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.id', $assignment->employee_id);
    }

    public function test_employee_main_position_prefers_definitive(): void
    {
        $employee = Employee::factory()->active()->create();
        PositionAssignment::factory()->atBrmLevel(2)->for($employee)->create(['assignment_status' => 'Plt']);
        $definitive = PositionAssignment::factory()->atBrmLevel(3)->for($employee)->create();

        $this->getJson("/api/v1/employees/{$employee->id}")
            ->assertJsonPath('data.position.id', $definitive->position_id)
            ->assertJsonPath('data.position.brm_level', 3);
    }

    public function test_organization_lists_active_holders_with_supervisors(): void
    {
        $manager = PositionAssignment::factory()->atBrmLevel(1)->create();
        $staff = PositionAssignment::factory()->atBrmLevel(3)->create();
        $staff->supervisors()->attach($manager);
        PositionAssignment::factory()->finished()->create(['position_id' => $staff->position_id]);

        $this->getJson('/api/v1/organization')
            ->assertJsonPath('data.positions.0.employees.0.assignment_id', $manager->id)
            ->assertJsonCount(1, 'data.positions.1.employees')
            ->assertJsonPath('data.positions.1.employees.0.supervisor_assignment_ids', [$manager->id]);
    }

    public function test_masters_used_by_assignment_cannot_be_deleted(): void
    {
        $object = PlacementObject::factory()->create();
        $assignment = PositionAssignment::factory()->create();
        $assignment->placementObjects()->attach($object);

        $this->deleteJson("/api/v1/positions/{$assignment->position_id}")->assertConflict();
        $this->deleteJson("/api/v1/sub-units/{$assignment->sub_unit_id}")->assertConflict();
        $this->deleteJson("/api/v1/placement-objects/{$object->id}")->assertConflict();
    }

    public function test_employee_with_assignment_cannot_be_deleted(): void
    {
        $employee = Employee::factory()->create();   // Belum Aktif
        PositionAssignment::factory()->for($employee)->create();

        $this->deleteJson("/api/v1/employees/{$employee->id}")->assertConflict();
    }

    public function test_sub_unit_and_object_count_only_active_assignments(): void
    {
        $subUnit = SubUnit::factory()->create();
        $object = PlacementObject::factory()->hasAttached($subUnit)->create();
        $active = PositionAssignment::factory()->for($subUnit)->create();
        $active->placementObjects()->attach($object);
        PositionAssignment::factory()->finished()->for($subUnit)->create();

        $this->getJson("/api/v1/sub-units/{$subUnit->id}")->assertJsonPath('data.assignment_count', 1);
        $this->getJson("/api/v1/placement-objects/{$object->id}")->assertJsonPath('data.assignment_count', 1);
    }

    public function test_sub_unit_with_active_assignment_cannot_be_released_from_object(): void
    {
        [$gunungMas, $malabar] = SubUnit::factory()->count(2)->create();
        $object = PlacementObject::factory()->hasAttached([$gunungMas, $malabar])->create();
        PositionAssignment::factory()->for($gunungMas)->create()->placementObjects()->attach($object);

        $this->putJson("/api/v1/placement-objects/{$object->id}", [
            'name' => $object->name, 'category_id' => $object->category_id,
            'sub_unit_ids' => [$malabar->id], 'description' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.sub_unit_ids.0', "{$gunungMas->name} masih punya penugasan aktif di objek ini, jadi belum bisa dilepas");
    }

    public function test_decree_file_returns_404_when_missing(): void
    {
        $assignment = PositionAssignment::factory()->create();

        $this->getJson("/api/v1/position-assignments/{$assignment->id}/decree-file")
            ->assertNotFound()
            ->assertJsonPath('message', 'File tidak ditemukan.');
    }
}
