<?php

namespace Tests\Feature\Assignment;

use App\Models\Employee;
use App\Models\PositionAssignment;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PositionAssignmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pengujian data awal penempatan semua karyawan (PositionAssignmentSeeder, Database Karyawan Standar Oktober 2026).
 */
class PositionAssignmentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_places_every_employee_from_the_standardized_sheet(): void
    {
        $this->seed(DatabaseSeeder::class);

        $rows = json_decode(file_get_contents(PositionAssignmentSeeder::DATA_FILE), true);
        $this->assertSame(count($rows), PositionAssignment::count());
        $this->assertSame(0, DB::table('assignment_supervisors')->count());   // atasan belum diisi

        $manager = Employee::where('registration_number', '01011383042042')->firstOrFail()->assignments()->with('position')->sole();
        $this->assertSame('Manager', $manager->position->title);
        $this->assertSame('Plh', $manager->assignment_status);

        // DZULFI: Asisten Diperbantukan di Afdeling Gunung Mas.
        $dzulfi = Employee::where('nik', '8006026')->firstOrFail()->assignments()->with('position', 'subUnit')->sole();
        $this->assertSame('Asisten Diperbantukan', $dzulfi->position->title);
        $this->assertSame('AFD50', $dzulfi->subUnit->afdeling_code);

        // Karyawan casual ikut ditempatkan, lengkap dengan objek penempatannya.
        $parking = Employee::where('registration_number', '7530')->firstOrFail()->assignments()->with('position', 'placementObjects')->sole();
        $this->assertSame('Karyawan Parkir', $parking->position->title);
        $this->assertSame(['Parkir'], $parking->placementObjects->pluck('name')->all());
    }

    public function test_running_twice_does_not_duplicate_assignments(): void
    {
        $this->seed(DatabaseSeeder::class);
        $objectLinks = DB::table('assignment_placement_objects')->count();

        $this->seed(PositionAssignmentSeeder::class);

        $rows = json_decode(file_get_contents(PositionAssignmentSeeder::DATA_FILE), true);
        $this->assertSame(count($rows), PositionAssignment::count());
        $this->assertSame($objectLinks, DB::table('assignment_placement_objects')->count());
    }
}
