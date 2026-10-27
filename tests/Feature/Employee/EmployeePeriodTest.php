<?php

namespace Tests\Feature\Employee;

use App\Enums\DocumentScope;
use App\Enums\ProposalStatus;
use App\Models\Employee;
use App\Models\EmployeeActivation;
use App\Models\EmployeeTermination;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Pengujian PERIODE DATA (?period=YYYY-MM) di daftar karyawan & ekspor Excel.
 * Hari ini dikunci 20 Oktober 2026, jadi 2026-10 = bulan berjalan dan 2026-09 = acuan 30 September 2026.
 */
class EmployeePeriodTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 10:00:00');
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_without_period_keeps_todays_behaviour(): void
    {
        // Kolom salinan status dipakai apa adanya tanpa periode (tidak dihitung ulang).
        $employee = Employee::factory()->create(['active_status' => 'Aktif']);

        $this->assertSame('Aktif', $this->employeeIn('', $employee)['active_status']);
        $this->assertSame('Belum Aktif', $this->employeeIn('?period=2026-10', $employee)['active_status']);
    }

    public function test_activation_counts_from_its_effective_date(): void
    {
        $employee = Employee::factory()->create();
        $this->activate($employee, '2026-10-15');

        $september = $this->employeeIn('?period=2026-09', $employee);
        $this->assertSame('Belum Aktif', $september['active_status']);
        $this->assertSame(0, $september['service_months']);

        $this->assertSame('Aktif', $this->employeeIn('?period=2026-10', $employee)['active_status']);
    }

    public function test_cancelled_activation_is_ignored(): void
    {
        $employee = Employee::factory()->create();
        $this->activate($employee, '2026-01-01', ProposalStatus::Cancelled);

        $this->assertSame('Belum Aktif', $this->employeeIn('?period=2026-09', $employee)['active_status']);
    }

    public function test_termination_counts_from_its_effective_date(): void
    {
        $employee = Employee::factory()->create();
        $this->activate($employee, '2020-01-01');
        $this->terminate($employee, '2026-10-01');

        $september = $this->employeeIn('?period=2026-09', $employee);
        $this->assertSame('Aktif', $september['active_status']);
        $this->assertNull($september['inactive_since']);
        $this->assertSame(80, $september['service_months']);   // 1 Jan 2020 s.d. 30 Sep 2026

        $october = $this->employeeIn('?period=2026-10', $employee);
        $this->assertSame('Nonaktif', $october['active_status']);
        $this->assertSame('2026-10-01', $october['inactive_since']);
        $this->assertSame(81, $october['service_months']);     // berhenti dihitung di TMT nonaktif
    }

    public function test_position_follows_assignments_running_on_the_reference_date(): void
    {
        $employee = Employee::factory()->create();
        PositionAssignment::factory()->for($employee)->for(Position::factory()->state(['title' => 'Admin Gudang']))
            ->create(['start_date' => '2025-01-01', 'end_date' => '2026-09-30']);
        PositionAssignment::factory()->for($employee)->for(Position::factory()->state(['title' => 'Admin I Tata Usaha']))
            ->create(['start_date' => '2026-10-01']);

        $this->assertSame('Admin Gudang', $this->employeeIn('?period=2026-09', $employee)['position']['title']);
        $this->assertSame('Admin I Tata Usaha', $this->employeeIn('?period=2026-10', $employee)['position']['title']);
        $this->assertSame('Admin I Tata Usaha', $this->employeeIn('', $employee)['position']['title']);
        $this->assertNull($this->employeeIn('?period=2024-12', $employee)['position']);
    }

    public function test_past_period_uses_last_day_of_month_including_leap_february(): void
    {
        $employee = Employee::factory()->create();
        $this->activate($employee, '2023-11-29');

        $this->assertSame(2, $this->employeeIn('?period=2024-01', $employee)['service_months']);   // s.d. 31 Jan 2024
        $this->assertSame(3, $this->employeeIn('?period=2024-02', $employee)['service_months']);   // s.d. 29 Feb 2024
    }

    public function test_rejects_future_or_malformed_period(): void
    {
        $this->getJson('/api/v1/employees?period=2026-11')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.period.0', 'Periode Data tidak boleh melewati bulan berjalan');

        foreach (['2026-13', '2026-9', 'abc'] as $period) {
            $this->getJson("/api/v1/employees?period={$period}")
                ->assertUnprocessable()
                ->assertJsonPath('errors.period.0', 'Periode Data harus berformat YYYY-MM, mis. 2026-09');
        }

        $this->getJson('/api/v1/employees/export?period=2026-11')->assertUnprocessable()->assertJsonValidationErrors('period');
    }

    public function test_export_uses_status_position_and_month_of_the_period(): void
    {
        $leaving = Employee::factory()->create(['name' => 'Ani Keluar']);
        $this->activate($leaving, '2020-01-01');
        $this->terminate($leaving, '2026-10-01');
        PositionAssignment::factory()->for($leaving)->for(Position::factory()->state(['title' => 'Admin Gudang']))
            ->create(['start_date' => '2025-01-01', 'end_date' => '2026-09-30']);

        $joining = Employee::factory()->create(['name' => 'Budi Baru']);
        $this->activate($joining, '2026-10-15');

        $response = $this->get('/api/v1/employees/export?period=2026-09&active_status=Aktif')->assertOk();
        $this->assertStringContainsString(
            'filename="Database Karyawan PTPN I Regional 2 Unit Agrowisata - September 2026.xlsx"',
            $response->headers->get('Content-Disposition'),
        );

        $path = $response->baseResponse->getFile()->getPathname();
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        // Per 30 September 2026: Ani masih Aktif & masih Admin Gudang; Budi belum aktif.
        $this->assertSame('ANI KELUAR', $sheet->getCell('G4')->getValue());
        $this->assertSame('ADMIN GUDANG', $sheet->getCell('Q4')->getValue());
        $this->assertNull($sheet->getCell('G5')->getValue());
    }

    /** Ambil satu karyawan dari GET /employees (dengan query periode). */
    private function employeeIn(string $query, Employee $employee): array
    {
        $employees = $this->getJson('/api/v1/employees'.$query)->assertOk()->json('data');

        return collect($employees)->firstWhere('id', $employee->id);
    }

    /** Usulan Penambahan (Penerimaan Baru) dengan TMT tertentu. */
    private function activate(Employee $employee, string $effectiveDate, ProposalStatus $status = ProposalStatus::Effective): void
    {
        EmployeeActivation::create([
            'status' => $status,
            'document_scope' => DocumentScope::Collective,
            'created_by' => $this->user->id,
            'activated_at' => now(),
        ])->items()->create([
            'employee_id' => $employee->id,
            'type' => 'Penerimaan Baru',
            'effective_date' => $effectiveDate,
        ]);
    }

    /** Usulan Penonaktifan (Mengundurkan Diri) BERLAKU dengan TMT tertentu. */
    private function terminate(Employee $employee, string $effectiveDate): void
    {
        EmployeeTermination::create([
            'status' => ProposalStatus::Effective,
            'document_scope' => DocumentScope::Collective,
            'created_by' => $this->user->id,
            'activated_at' => now(),
        ])->items()->create([
            'employee_id' => $employee->id,
            'type' => 'Mengundurkan Diri',
            'effective_date' => $effectiveDate,
        ]);
    }
}
