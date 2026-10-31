<?php

namespace Tests\Feature\Dashboard;

use App\Models\Employee;
use App\Models\PlacementCategory;
use App\Models\PlacementObject;
use App\Models\User;
use Database\Seeders\SignatureSettingSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Payroll\PayrollTestHelpers;

/**
 * Data uji dashboard = skenario Daftar Upah Oktober 2026 (PayrollTestHelpers: Ani, Budi nonaktif 16 Okt, Cici)
 * ditambah Dina (Karyawan Tetap, Perempuan) dan objek penempatan "Tea Bridge" (kategori "Wisata") untuk Ani.
 * Hari ini 5 November 2026.
 */
trait DashboardTestHelpers
{
    use PayrollTestHelpers;

    protected Employee $dina;

    protected PlacementObject $teaBridge;

    protected function seedDashboardScenario(): void
    {
        $this->travelTo('2026-11-05 10:00:00');
        Sanctum::actingAs(User::factory()->create(['name' => 'Admin SDM']));
        $this->seed(SignatureSettingSeeder::class);
        $this->seedPayrollScenario();

        $this->dina = Employee::factory()->active()->create(['name' => 'Dina', 'gender' => 'Perempuan', 'employment_status' => 'Karyawan Tetap']);

        $this->teaBridge = PlacementObject::factory()->create([
            'name' => 'Tea Bridge',
            'category_id' => PlacementCategory::factory()->create(['name' => 'Wisata'])->id,
        ]);
        $this->ani->assignments()->first()->placementObjects()->attach($this->teaBridge->id);
    }

    /** Data satu widget. */
    protected function widget(string $key, string $query = 'from=2026-10-01&to=2026-10-31'): array
    {
        return $this->getJson("/api/v1/dashboard/widgets/{$key}?{$query}")->assertOk()->json('data');
    }

    /** Jumlah satu kolom Daftar Upah (GET /payroll) satu bulan. */
    protected function payrollTotal(string $period, string $column = 'net'): int
    {
        return (int) collect($this->getJson("/api/v1/payroll?period={$period}")->assertOk()->json('data.rows'))->sum($column);
    }
}
