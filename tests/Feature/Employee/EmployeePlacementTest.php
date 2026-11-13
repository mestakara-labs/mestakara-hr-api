<?php

namespace Tests\Feature\Employee;

use App\Models\Division;
use App\Models\Employee;
use App\Models\PlacementCategory;
use App\Models\PlacementObject;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\SubUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian data PENEMPATAN di GET /employees & /employees/{id}:
 * `position` (jabatan utama + bidang, sub unit, objek) dan `active_positions` (semua penugasan berjalan).
 * Hari ini dikunci 20 Oktober 2026. Lazy loading dilarang supaya N+1 langsung ketahuan.
 */
class EmployeePlacementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 10:00:00');
        Model::preventLazyLoading();
        Sanctum::actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        parent::tearDown();
    }

    public function test_single_definitive_assignment_includes_division_sub_unit_and_object(): void
    {
        $employee = Employee::factory()->create();
        $division = Division::factory()->create(['name' => 'Operasional']);
        $position = Position::factory()->create([
            'title' => 'Asisten Manager Operasional', 'division_id' => $division->id, 'brm_level' => 2, 'job_grade' => 12,
        ]);
        $subUnit = SubUnit::factory()->create(['name' => 'Agrowisata Gunung Mas', 'afdeling_code' => 'AFD03']);
        $object = PlacementObject::factory()
            ->for(PlacementCategory::factory()->state(['name' => 'Wahana']), 'category')
            ->create(['name' => 'ATV']);
        $assignment = PositionAssignment::factory()->for($employee)->for($position)->for($subUnit)->create();
        $assignment->placementObjects()->attach($object);

        $data = $this->employeeIn('', $employee);

        $expected = [
            'assignment_id' => $assignment->id,
            'id' => $position->id,
            'title' => 'Asisten Manager Operasional',
            'brm_level' => 2,
            'job_grade' => 12,
            'assignment_status' => 'Definitif',
            'division' => ['id' => $division->id, 'name' => 'Operasional'],
            'sub_unit' => ['id' => $subUnit->id, 'name' => 'Agrowisata Gunung Mas', 'afdeling_code' => 'AFD03'],
            'placement_object' => ['id' => $object->id, 'name' => 'ATV', 'category_name' => 'Wahana'],
        ];
        $this->assertSame($expected, $data['position']);
        $this->assertSame([$expected], $data['active_positions']);
    }

    public function test_definitive_comes_first_when_employee_holds_two_positions(): void
    {
        $employee = Employee::factory()->create();
        PositionAssignment::factory()->for($employee)->atBrmLevel(2)->create(['assignment_status' => 'Plt']);
        $definitive = PositionAssignment::factory()->for($employee)->atBrmLevel(3)->create(['assignment_status' => 'Definitif']);

        $data = $this->employeeIn('', $employee);

        $this->assertSame($definitive->id, $data['position']['assignment_id']);
        $this->assertCount(2, $data['active_positions']);
        $this->assertSame('Definitif', $data['active_positions'][0]['assignment_status']);
        $this->assertSame('Plt', $data['active_positions'][1]['assignment_status']);
    }

    public function test_only_the_first_placement_object_is_sent(): void
    {
        $employee = Employee::factory()->create();
        $objects = PlacementObject::factory()->count(2)->create();
        $assignment = PositionAssignment::factory()->for($employee)->create();
        $assignment->placementObjects()->attach($objects->reverse()->pluck('id'));

        $data = $this->employeeIn('', $employee);

        $this->assertSame($objects->pluck('id')->sort()->first(), $data['position']['placement_object']['id']);
    }

    public function test_missing_object_and_division_are_null(): void
    {
        $employee = Employee::factory()->create();
        PositionAssignment::factory()->for($employee)->atBrmLevel(1)->create();

        $data = $this->employeeIn('', $employee);

        $this->assertNull($data['position']['placement_object']);
        $this->assertNull($data['position']['division']);
    }

    public function test_unplaced_employee_has_no_position(): void
    {
        $employee = Employee::factory()->create();

        $data = $this->employeeIn('', $employee);

        $this->assertNull($data['position']);
        $this->assertSame([], $data['active_positions']);
    }

    public function test_period_uses_assignments_running_on_the_reference_date(): void
    {
        $employee = Employee::factory()->create();
        PositionAssignment::factory()->for($employee)->for(Position::factory()->state(['title' => 'Admin Gudang']))
            ->create(['start_date' => '2025-01-01', 'end_date' => '2026-08-31']);
        PositionAssignment::factory()->for($employee)->for(Position::factory()->state(['title' => 'Admin I Tata Usaha']))
            ->create(['start_date' => '2026-09-01', 'end_date' => '2026-10-05']);

        $september = $this->employeeIn('?period=2026-09', $employee);
        $this->assertSame('Admin I Tata Usaha', $september['position']['title']);
        $this->assertSame(['Admin I Tata Usaha'], array_column($september['active_positions'], 'title'));

        $today = $this->employeeIn('', $employee);
        $this->assertNull($today['position']);
        $this->assertSame([], $today['active_positions']);
    }

    public function test_show_includes_placement_data(): void
    {
        $employee = Employee::factory()->create();
        $assignment = PositionAssignment::factory()->for($employee)->create();
        $assignment->placementObjects()->attach(PlacementObject::factory()->create());

        $this->getJson("/api/v1/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.position.sub_unit.id', $assignment->sub_unit_id)
            ->assertJsonCount(1, 'data.active_positions')
            ->assertJsonStructure(['data' => ['position' => ['division', 'sub_unit', 'placement_object']]]);
    }

    public function test_index_query_count_does_not_grow_with_employees(): void
    {
        $this->createPlacedEmployee();
        $queriesForOne = $this->countIndexQueries('');
        $queriesForOnePeriod = $this->countIndexQueries('?period=2026-09');

        foreach (range(1, 4) as $ignored) {
            $this->createPlacedEmployee();
        }

        $this->assertSame($queriesForOne, $this->countIndexQueries(''));
        $this->assertSame($queriesForOnePeriod, $this->countIndexQueries('?period=2026-09'));
    }

    /** Karyawan dengan 2 penugasan aktif, masing-masing ber-objek penempatan. */
    private function createPlacedEmployee(): void
    {
        $employee = Employee::factory()->create();

        foreach (['Definitif', 'Plt'] as $status) {
            PositionAssignment::factory()->for($employee)->create(['assignment_status' => $status])
                ->placementObjects()->attach(PlacementObject::factory()->create());
        }
    }

    /** Jumlah query database saat memanggil GET /employees. */
    private function countIndexQueries(string $query): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/employees'.$query)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /** Ambil satu karyawan dari GET /employees (dengan query periode). */
    private function employeeIn(string $query, Employee $employee): array
    {
        $employees = $this->getJson('/api/v1/employees'.$query)->assertOk()->json('data');

        return collect($employees)->firstWhere('id', $employee->id);
    }
}
