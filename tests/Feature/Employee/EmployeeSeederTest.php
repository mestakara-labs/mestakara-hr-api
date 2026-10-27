<?php

namespace Tests\Feature\Employee;

use App\Enums\EmployeeActiveStatus;
use App\Models\Employee;
use App\Models\EmployeeActivationItem;
use App\Services\EmployeeStatusService;
use Database\Seeders\EmployeeSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pengujian data awal karyawan (EmployeeSeeder).
 */
class EmployeeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_every_employee_as_active_with_effective_activation_history(): void
    {
        $this->seed([UserSeeder::class, EmployeeSeeder::class]);

        $rows = json_decode(file_get_contents(EmployeeSeeder::DATA_FILE), true);
        $this->assertSame(count($rows), Employee::count());
        $this->assertSame(0, Employee::where('active_status', '!=', EmployeeActiveStatus::Active->value)->count());
        $this->assertSame(count($rows), EmployeeActivationItem::count());

        $employee = Employee::where('registration_number', '00000019011951')->firstOrFail();
        $this->assertSame('Karyawan Tetap', $employee->employment_status);
        $this->assertSame('2026-07-09', $employee->hire_date->toDateString());
        $this->assertSame(EmployeeActiveStatus::Active, app(EmployeeStatusService::class)->statusOn($employee));
    }

    public function test_running_twice_does_not_duplicate_employees_or_activations(): void
    {
        $this->seed([UserSeeder::class, EmployeeSeeder::class]);
        $this->seed(EmployeeSeeder::class);

        $rows = json_decode(file_get_contents(EmployeeSeeder::DATA_FILE), true);
        $this->assertSame(count($rows), Employee::count());
        $this->assertSame(count($rows), EmployeeActivationItem::count());
    }
}
