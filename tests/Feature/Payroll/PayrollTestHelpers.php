<?php

namespace Tests\Feature\Payroll;

use App\Models\CostCenter;
use App\Models\Division;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeCostCenter;
use App\Models\EmployeeWageScheme;
use App\Models\PayrollGroup;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\SubUnit;
use Tests\Feature\Attendance\AttendanceTestHelpers;

/**
 * Data uji Daftar Upah periode Oktober 2026 (31 hari; hari ini 5 November 2026 supaya periode boleh dikunci).
 *
 *   Ani   Harian, Gaji Pokok 2.600.000 / HKE 26 (Rp/hari 100.000), dibatasi 20 hari, uang makan 10.000/hari,
 *         Ops & Jab 50.000, Insentif 25.000, kelompok "Gunung Mas", bidang "Bidang Tanaman", rekening & cost center.
 *         K 22 hari (melebihi batas = peringatan saja) -> 22 × 100.000 = 2.200.000; uang makan 220.000;
 *         lembur reguler 2 jam = 52.600 (tarif 15.029) -> Jumlah 2.547.600
 *   Budi  Flat Bulanan 3.100.000, kelompok "Petugas Parkir", tanpa jabatan (Tanpa Bidang), nonaktif mulai 16 Okt
 *         -> aktif 15 hari -> round(3.100.000 × 15 ÷ 31) = 1.500.000
 *   Cici  tanpa skema -> semua 0, Tanpa Kelompok & Tanpa Bidang
 */
trait PayrollTestHelpers
{
    use AttendanceTestHelpers;

    protected Employee $ani;

    protected Employee $budi;

    protected Employee $cici;

    protected PayrollGroup $mountain;

    protected PayrollGroup $parking;

    protected Division $plantation;

    protected function seedPayrollScenario(): void
    {
        $this->mountain = PayrollGroup::create(['name' => 'Gunung Mas', 'sort_order' => 1]);
        $this->parking = PayrollGroup::create(['name' => 'Petugas Parkir', 'sort_order' => 2]);
        $this->plantation = Division::factory()->create(['name' => 'Bidang Tanaman']);

        $this->ani = $this->casual('Ani', '7761');
        PositionAssignment::factory()->create([
            'employee_id' => $this->ani->id,
            'position_id' => Position::factory()->create(['title' => 'Pemandu Wisata', 'division_id' => $this->plantation->id])->id,
            'sub_unit_id' => SubUnit::factory()->create(['name' => 'Kebun Gunung Mas', 'afdeling_code' => '01'])->id,
        ]);
        EmployeeWageScheme::create([
            'employee_id' => $this->ani->id, 'wage_type' => 'Harian', 'base_salary' => 2_600_000, 'working_days' => 26,
            'is_capped' => true, 'cap_days' => 20, 'operational_position_allowance' => 50_000, 'incentive' => 25_000,
            'has_meal_allowance' => true, 'meal_allowance_per_day' => 10_000, 'payroll_group_id' => $this->mountain->id,
            'effective_date' => '2026-01-01',
        ]);
        EmployeeBankAccount::create([
            'employee_id' => $this->ani->id, 'bank_name' => 'BRI', 'account_number' => '0123456', 'account_holder' => 'ANI', 'effective_date' => '2026-01-01',
        ]);
        EmployeeCostCenter::create([
            'employee_id' => $this->ani->id, 'effective_date' => '2026-01-01',
            'cost_center_id' => CostCenter::create(['code' => 'CC-01', 'name' => 'Wisata Gunung Mas'])->id,
        ]);
        for ($day = 1; $day <= 22; $day++) {
            $this->storeCode($this->ani, sprintf('2026-10-%02d', $day), 'K');
        }
        $this->storeOvertime($this->ani, '2026-10-05', '17:00', '19:00');

        $this->budi = $this->casual('Budi', '7530', 'Karyawan Daily Worker');
        EmployeeWageScheme::create([
            'employee_id' => $this->budi->id, 'wage_type' => 'Flat Bulanan', 'base_salary' => 3_100_000, 'working_days' => 25,
            'payroll_group_id' => $this->parking->id, 'effective_date' => '2026-01-01',
        ]);
        $this->terminate($this->budi, '2026-10-16');

        $this->cici = $this->casual('Cici', '8001');
    }

    /** @return array<string, array<string, mixed>> baris GET /payroll per nama */
    protected function payrollRows(string $groupBy = 'payroll_group'): array
    {
        return collect($this->getJson("/api/v1/payroll?period=2026-10&group_by={$groupBy}")->assertOk()->json('data.rows'))
            ->keyBy('name')->all();
    }
}
