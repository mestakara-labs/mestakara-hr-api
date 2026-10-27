<?php

namespace Tests\Feature\Payroll;

use App\Models\PayrollDeduction;
use App\Models\PayrollDeductionChange;
use App\Models\PayrollPeriodEvent;
use App\Models\PayrollSnapshotRow;
use App\Models\PayrollSnapshotSigner;
use App\Models\User;
use Database\Seeders\SignatureSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian Daftar Upah (Tahap 4): rumus, pengelompokan, potongan, salin potongan, kunci & buka kunci.
 * Data uji di PayrollTestHelpers.
 */
class PayrollTest extends TestCase
{
    use PayrollTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-05 10:00:00');
        Sanctum::actingAs(User::factory()->create(['name' => 'Admin SDM']));
        $this->seed(SignatureSettingSeeder::class);
        $this->seedPayrollScenario();
    }

    // -------------------------------------------------------------------------
    // Rumus
    // -------------------------------------------------------------------------

    public function test_daily_wage_is_capped_with_meal_allowance_overtime_and_allowances(): void
    {
        $ani = $this->payrollRows()['Ani'];

        $this->assertSame([
            'wage_type' => 'Harian', 'hke' => 26, 'present_days' => 22, 'active_days' => 31, 'calendar_days' => 31,
            'base_salary' => 2_600_000, 'daily_rate' => 100_000, 'cap_days' => 20, 'base_pay' => 2_000_000,
            'regular_overtime_pay' => 52_600, 'dw_overtime_pay' => 0, 'overtime_pay' => 52_600,
            'operational_allowance' => 50_000, 'incentive' => 25_000, 'meal_allowance' => 220_000,
            'gross' => 2_347_600, 'deductions_total' => 0, 'net' => 2_347_600,
        ], array_intersect_key($ani, array_flip([
            'wage_type', 'hke', 'present_days', 'active_days', 'calendar_days', 'base_salary', 'daily_rate', 'cap_days',
            'base_pay', 'regular_overtime_pay', 'dw_overtime_pay', 'overtime_pay', 'operational_allowance', 'incentive',
            'meal_allowance', 'gross', 'deductions_total', 'net',
        ])));
        $this->assertSame(['K 22 melebihi batas 20 hari; yang dibayar 20'], $ani['warnings']);
        $this->assertSame('Pemandu Wisata', $ani['position']);
        $this->assertSame(['name' => 'Kebun Gunung Mas', 'afdeling_code' => '01'], $ani['sub_unit']);
        $this->assertSame(['name' => 'Gunung Mas'], array_intersect_key($ani['payroll_group'], ['name' => 1]));
        $this->assertSame('Bidang Tanaman', $ani['division']['name']);
        $this->assertSame('CC-01', $ani['cost_center']['code']);
        $this->assertSame(['bank_name' => 'BRI', 'account_number' => '0123456', 'account_name' => 'ANI'], $ani['bank_account']);
        $this->assertSame(['bpjs' => 0, 'pph21' => 0, 'unit' => 0], $ani['deductions']);
        $this->assertSame($this->mountain->id, $ani['group_key']);
    }

    public function test_flat_monthly_is_prorated_and_missing_scheme_gives_zero_with_warning(): void
    {
        $rows = $this->payrollRows();

        $budi = $rows['Budi'];
        $this->assertSame(15, $budi['active_days']);
        $this->assertSame(1_500_000, $budi['base_pay']);
        $this->assertSame(1_500_000, $budi['gross']);
        $this->assertNull($budi['division']);

        $cici = $rows['Cici'];
        $this->assertNull($cici['wage_type']);
        $this->assertSame(0, $cici['gross']);
        $this->assertSame(0, $cici['net']);
        $this->assertSame(['Skema upah belum diatur'], $cici['warnings']);
        $this->assertSame('tanpa', $cici['group_key']);
    }

    public function test_groups_by_payroll_group_and_division_with_counts_adding_up(): void
    {
        $data = $this->getJson('/api/v1/payroll?period=2026-10')->assertOk()->json('data');

        $this->assertSame(['Gunung Mas', 'Petugas Parkir', 'Tanpa Kelompok'], array_column($data['groups'], 'name'));
        $this->assertSame(3, array_sum(array_column($data['groups'], 'employee_count')));
        $this->assertSame(['employees' => 3, 'gross' => 3_847_600, 'deductions' => 0, 'net' => 3_847_600], $data['summary']);
        $this->assertSame([['name' => 'Kebun Gunung Mas', 'afdeling_code' => '01']], $data['groups'][0]['sub_units']);
        $this->assertSame(['place' => 'Gunung Mas', 'uses_general_payroll' => true, 'uses_general_spkl' => true], $data['groups'][0]['signature']);
        $this->assertSame(2_347_600, $data['groups'][0]['totals']['gross']);
        $this->assertSame(['without_scheme' => 1, 'without_bank_account' => 2, 'without_cost_center' => 2, 'without_payroll_group' => 1,
            'without_division' => 2, 'attendance_uploaded' => true, 'dw_not_matched' => 0, 'k_over_cap' => 1], $data['readiness']);
        $this->assertSame(['period' => '2026-10', 'status' => 'draf', 'locked_at' => null, 'locked_by' => null, 'can_lock' => true, 'lock_history' => []],
            array_intersect_key($data, array_flip(['period', 'status', 'locked_at', 'locked_by', 'can_lock', 'lock_history'])));

        $byDivision = $this->getJson('/api/v1/payroll?period=2026-10&group_by=division')->assertOk()->json('data');
        $this->assertSame(['Bidang Tanaman', 'Tanpa Bidang'], array_column($byDivision['groups'], 'name'));
        $this->assertSame([1, 2], array_column($byDivision['groups'], 'employee_count'));
        $this->assertSame($this->plantation->id, collect($byDivision['rows'])->firstWhere('name', 'Ani')['group_key']);
    }

    // -------------------------------------------------------------------------
    // Potongan
    // -------------------------------------------------------------------------

    public function test_updates_deductions_for_one_and_many_employees_changing_only_sent_fields(): void
    {
        $this->putJson('/api/v1/payroll/deductions', ['period' => '2026-10', 'employee_ids' => [$this->ani->id], 'bpjs' => 50_000, 'unit' => 10_000])
            ->assertOk()->assertJsonPath('data.employees', 1);
        $this->putJson('/api/v1/payroll/deductions', ['period' => '2026-10', 'employee_ids' => [$this->ani->id, $this->budi->id], 'pph21' => 5_000])
            ->assertOk()->assertJsonPath('data.employees', 2);

        $rows = $this->payrollRows();
        $this->assertSame(['bpjs' => 50_000, 'pph21' => 5_000, 'unit' => 10_000], $rows['Ani']['deductions']);
        $this->assertSame(65_000, $rows['Ani']['deductions_total']);
        $this->assertSame(2_282_600, $rows['Ani']['net']);
        $this->assertSame(['bpjs' => 0, 'pph21' => 5_000, 'unit' => 0], $rows['Budi']['deductions']);
        $this->assertSame(4, PayrollDeductionChange::count());   // Ani: bpjs, unit, pph21 · Budi: pph21
    }

    public function test_rejects_deductions_above_gross_negative_numbers_and_employees_outside_the_list(): void
    {
        $this->putJson('/api/v1/payroll/deductions', ['period' => '2026-10', 'employee_ids' => [$this->cici->id], 'bpjs' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['employee_ids.0' => ['Cici: potongan Rp1 melebihi jumlah upah Rp0']]);

        $this->putJson('/api/v1/payroll/deductions', ['period' => '2026-10', 'employee_ids' => [$this->ani->id], 'bpjs' => -5])
            ->assertUnprocessable()->assertJsonPath('errors.bpjs.0', 'Potongan BPJS harus berupa angka bulat minimal 0');

        $this->putJson('/api/v1/payroll/deductions', ['period' => '2026-10', 'employee_ids' => [$this->ani->id]])
            ->assertUnprocessable()->assertJsonPath('errors.bpjs.0', 'Isi minimal satu potongan (BPJS, PPh 21, atau Kebun/Unit)');

        $this->assertSame(0, PayrollDeduction::count());
    }

    public function test_copy_previous_previews_without_saving_and_copies_only_employees_still_at_zero(): void
    {
        PayrollDeduction::create(['period' => '2026-09', 'employee_id' => $this->ani->id, 'bpjs' => 40_000, 'pph21' => 0, 'unit' => 5_000]);
        PayrollDeduction::create(['period' => '2026-09', 'employee_id' => $this->budi->id, 'bpjs' => 30_000, 'pph21' => 0, 'unit' => 0]);
        PayrollDeduction::create(['period' => '2026-10', 'employee_id' => $this->budi->id, 'bpjs' => 0, 'pph21' => 1_000, 'unit' => 0]);

        $preview = $this->postJson('/api/v1/payroll/deductions/copy-previous', ['period' => '2026-10', 'save' => false])->assertOk()->json('data');
        $this->assertSame('2026-09', $preview['source_period']);
        $this->assertSame(['Ani'], array_column($preview['employees'], 'name'));
        $this->assertSame(['bpjs' => 40_000, 'pph21' => 0, 'unit' => 5_000], $preview['totals']);
        $this->assertNull(PayrollDeduction::where('period', '2026-10')->where('employee_id', $this->ani->id)->first());

        $this->postJson('/api/v1/payroll/deductions/copy-previous', ['period' => '2026-10', 'save' => true])->assertOk();
        $rows = $this->payrollRows();
        $this->assertSame(['bpjs' => 40_000, 'pph21' => 0, 'unit' => 5_000], $rows['Ani']['deductions']);
        $this->assertSame(['bpjs' => 0, 'pph21' => 1_000, 'unit' => 0], $rows['Budi']['deductions']);
        $this->assertSame(2, PayrollDeductionChange::where('source', 'salin')->count());
    }

    // -------------------------------------------------------------------------
    // Kunci & buka kunci
    // -------------------------------------------------------------------------

    public function test_lock_keeps_a_snapshot_even_when_master_data_changes(): void
    {
        $this->postJson('/api/v1/payroll/lock', ['period' => '2026-10'])
            ->assertOk()->assertJsonPath('data.status', 'dikunci')->assertJsonPath('data.locked_by', 'Admin SDM');

        $this->assertSame(3, PayrollSnapshotRow::count());
        $this->assertTrue(PayrollSnapshotSigner::where('owner_type', 'umum')->where('document', 'daftar_upah')->exists());

        $this->ani->update(['name' => 'Ani Berganti Nama']);
        $this->mountain->update(['name' => 'Kelompok Baru']);

        $data = $this->getJson('/api/v1/payroll?period=2026-10')->assertOk()->json('data');
        $ani = collect($data['rows'])->firstWhere('employee_id', $this->ani->id);
        $this->assertSame('Ani', $ani['name']);
        $this->assertSame('Gunung Mas', $ani['payroll_group']['name']);
        $this->assertSame(2_347_600, $ani['gross']);
        $this->assertSame(['K 22 melebihi batas 20 hari; yang dibayar 20'], $ani['warnings']);
        $this->assertSame('Gunung Mas', $data['groups'][0]['name']);
        $this->assertFalse($data['can_lock']);
        $this->assertSame('kunci', $data['lock_history'][0]['action']);
    }

    public function test_locked_period_rejects_attendance_overtime_deductions_and_wage_schemes(): void
    {
        $this->postJson('/api/v1/payroll/lock', ['period' => '2026-10'])->assertOk();

        $this->putJson("/api/v1/employees/{$this->ani->id}/attendance", ['date' => '2026-10-25', 'status_code' => 'K', 'reason' => 'Koreksi'])
            ->assertStatus(423);
        $this->postJson('/api/v1/overtime-entries', [
            'period' => '2026-10', 'employee_id' => $this->ani->id, 'overtime_type' => 'reguler', 'date' => '2026-10-06',
            'start_time' => '17:00', 'end_time' => '18:00', 'description' => 'Acara', 'reason' => 'Tambahan lembur',
        ])->assertUnprocessable();
        $this->putJson('/api/v1/payroll/deductions', ['period' => '2026-10', 'employee_ids' => [$this->ani->id], 'bpjs' => 1])
            ->assertUnprocessable()->assertJsonPath('errors.period.0', 'Periode upah Oktober 2026 sudah dikunci');
        $this->postJson('/api/v1/payroll/deductions/copy-previous', ['period' => '2026-10', 'save' => true])->assertUnprocessable();

        $scheme = ['wage_type' => 'Harian', 'base_salary' => 2_700_000, 'working_days' => 26];
        $this->postJson("/api/v1/employees/{$this->ani->id}/wage-schemes", [...$scheme, 'effective_date' => '2026-10-15'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.effective_date.0', 'Berlaku Mulai 15/10/2026 mengubah upah periode Oktober 2026 yang sudah dikunci');
        $this->postJson('/api/v1/wage-schemes/bulk', ['employee_ids' => [$this->ani->id], 'effective_date' => '2026-09-01', 'base_salary' => 2_700_000])
            ->assertUnprocessable();
        $this->postJson("/api/v1/employees/{$this->ani->id}/wage-schemes", [...$scheme, 'effective_date' => '2026-11-01'])->assertCreated();

        $this->getJson('/api/v1/attendance-imports?period=2026-10')->assertOk()->assertJsonPath('data.period_status', 'dikunci');
    }

    public function test_cannot_lock_the_current_month_and_unlock_needs_a_reason(): void
    {
        $this->postJson('/api/v1/payroll/lock', ['period' => '2026-11'])
            ->assertUnprocessable()->assertJsonPath('errors.period.0', 'Periode belum berakhir');
        $this->postJson('/api/v1/payroll/lock', ['period' => '2026-12'])
            ->assertUnprocessable()->assertJsonPath('errors.period.0', 'Periode belum berakhir');

        $this->postJson('/api/v1/payroll/lock', ['period' => '2026-10'])->assertOk();
        $this->postJson('/api/v1/payroll/lock', ['period' => '2026-10'])->assertUnprocessable();
        $this->postJson('/api/v1/payroll/unlock', ['period' => '2026-10'])
            ->assertUnprocessable()->assertJsonPath('errors.reason.0', 'Alasan buka kunci wajib diisi');
        $this->postJson('/api/v1/payroll/unlock', ['period' => '2026-10', 'reason' => 'ok'])
            ->assertUnprocessable()->assertJsonPath('errors.reason.0', 'Alasan buka kunci minimal 5 karakter');

        $this->postJson('/api/v1/payroll/unlock', ['period' => '2026-10', 'reason' => 'Revisi lembur'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draf')
            ->assertJsonPath('data.lock_history.1', ['action' => 'buka', 'reason' => 'Revisi lembur', 'by' => 'Admin SDM', 'at' => now()->toIso8601String()]);

        $this->assertSame(0, PayrollSnapshotRow::count());
        $this->assertSame(0, PayrollSnapshotSigner::count());
        $this->assertSame(2, PayrollPeriodEvent::count());
        $this->putJson('/api/v1/payroll/deductions', ['period' => '2026-10', 'employee_ids' => [$this->ani->id], 'bpjs' => 1])->assertOk();
    }
}
