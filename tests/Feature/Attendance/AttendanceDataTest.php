<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceSource;
use App\Enums\PayrollPeriodStatus;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayChange;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\EmployeeWageScheme;
use App\Models\OvertimeSetting;
use App\Models\PayrollGroup;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian data baca Unggah Absensi (ringkasan daftar hadir, daftar lembur, detail karyawan) dan KOREKSI MANUAL.
 * Hari ini 20 Oktober 2026.
 */
class AttendanceDataTest extends TestCase
{
    use AttendanceTestHelpers, RefreshDatabase;

    private Employee $ani;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 10:00:00');
        Sanctum::actingAs(User::factory()->create(['name' => 'Admin SDM']));
        $this->ani = $this->casual('Ani', '7761');
    }

    public function test_summary_counts_codes_blank_days_overtime_and_warnings(): void
    {
        EmployeeWageScheme::create(['employee_id' => $this->ani->id, 'wage_type' => 'Harian', 'base_salary' => 2_500_000, 'is_capped' => true, 'cap_days' => 2, 'effective_date' => '2026-01-01']);
        $left = $this->casual('Budi', '7530');
        $this->terminate($left, '2026-10-11');   // aktif 1–10 Oktober
        Employee::factory()->active()->create(['name' => 'Tetap']);

        foreach ([1, 2, 3] as $day) {
            $this->storeCode($this->ani, "2026-10-0{$day}", 'K');
        }
        $this->storeCode($this->ani, '2026-10-04', 'L');
        $this->storeOvertime($this->ani, '2026-10-01', '17:00', '19:00');
        $this->storeOvertime($this->ani, '2026-10-02', '17:00', '18:30', 'dw');

        $rows = collect($this->getJson('/api/v1/attendance-summaries?period=2026-10')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(['Ani', 'Budi'], $rows->keys()->all());
        $ani = $rows['Ani'];
        $this->assertSame(['K' => 3, 'DW' => 0, 'I' => 0, 'S' => 0, 'M' => 0, 'C' => 0, 'L' => 1], $ani['codes']);
        $this->assertSame(27, $ani['blank_days']);
        $this->assertSame(['entries' => 1, 'minutes' => 120], $ani['regular_overtime']);
        $this->assertSame(['entries' => 1, 'minutes' => 90], $ani['dw_overtime']);
        $this->assertSame(['K melebihi batas 2 hari', '27 hari aktif tanpa kode'], $ani['warnings']);
        $this->assertSame(10, $rows['Budi']['blank_days']);

        // Skema Ani tanpa kelompok; Budi belum ditempatkan, belum punya skema & cost center.
        $this->assertNull($ani['payroll_group']);
        $this->assertSame(['position' => null, 'payroll_group' => null, 'cost_center' => null],
            array_intersect_key($rows['Budi'], array_flip(['position', 'payroll_group', 'cost_center'])));
    }

    public function test_summary_and_overtime_list_show_payroll_identity_on_reference_date(): void
    {
        [$group, $costCenter] = $this->giveIdentity($this->ani);
        // Jabatan / skema / cost center yang baru berlaku sesudah periode tidak dipakai.
        PositionAssignment::factory()->for($this->ani)->for(Position::factory()->state(['title' => 'Supervisor']))->create(['start_date' => '2026-11-01']);
        $this->storeOvertime($this->ani, '2026-10-08', '17:00', '18:00');

        $expected = [
            'position' => 'Pelaksana Wahana',
            'payroll_group' => ['id' => $group->id, 'name' => 'Gunung Mas'],
            'cost_center' => ['id' => $costCenter->id, 'code' => '4A44AGR006', 'name' => 'Wahana'],
        ];

        $summary = $this->getJson('/api/v1/attendance-summaries?period=2026-10')->assertOk()->json('data.0');
        $this->assertSame($expected, array_intersect_key($summary, $expected));

        $overtime = $this->getJson('/api/v1/overtime-entries?period=2026-10&type=lembur_reguler')->assertOk()->json('data.0');
        $this->assertSame($expected, array_intersect_key($overtime, $expected));
        $this->assertArrayNotHasKey('position', $overtime['employee']);   // sejajar sub_unit, bukan di dalam employee
    }

    public function test_payroll_identity_does_not_add_queries_per_employee(): void
    {
        OvertimeSetting::current();   // dibuat sekali di awal, supaya tidak ikut terhitung
        $this->giveIdentity($this->ani);
        $this->storeOvertime($this->ani, '2026-10-08', '17:00', '18:00');
        $few = $this->queryCount();

        foreach (['Budi', 'Cici', 'Dodi'] as $index => $name) {
            $employee = $this->casual($name, (string) (8000 + $index));
            $this->giveIdentity($employee);
            $this->storeOvertime($employee, '2026-10-08', '17:00', '18:00');
        }

        $this->assertSame($few, $this->queryCount());
    }

    /** Beri jabatan, skema upah berkelompok, & cost center yang berlaku sejak awal 2026. */
    private function giveIdentity(Employee $employee): array
    {
        $group = PayrollGroup::firstOrCreate(['name' => 'Gunung Mas'], ['sort_order' => 1]);
        $costCenter = CostCenter::firstOrCreate(['code' => '4A44AGR006'], ['name' => 'Wahana']);
        PositionAssignment::factory()->for($employee)->for(Position::factory()->state(['title' => 'Pelaksana Wahana']))->create(['start_date' => '2026-01-01']);
        EmployeeWageScheme::create([
            'employee_id' => $employee->id, 'wage_type' => 'Harian', 'base_salary' => 2_375_000,
            'payroll_group_id' => $group->id, 'effective_date' => '2026-01-01',
        ]);
        $employee->costCenterHistories()->create(['cost_center_id' => $costCenter->id, 'effective_date' => '2026-01-01']);

        return [$group, $costCenter];
    }

    /** Jumlah query untuk ringkasan daftar hadir + daftar lembur. */
    private function queryCount(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/attendance-summaries?period=2026-10')->assertOk();
        $this->getJson('/api/v1/overtime-entries?period=2026-10&type=lembur_reguler')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_overtime_list_per_type_with_warnings(): void
    {
        $this->storeCode($this->ani, '2026-10-07', 'L');
        $this->storeOvertime($this->ani, '2026-10-07', '22:00', '03:00');
        $this->storeOvertime($this->ani, '2026-10-08', '17:00', '18:00', 'dw');

        $this->getJson('/api/v1/overtime-entries?period=2026-10&type=lembur_reguler')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.date', '2026-10-07')
            ->assertJsonPath('data.0.start_time', '22:00')
            ->assertJsonPath('data.0.duration_minutes', 300)
            ->assertJsonPath('data.0.crosses_midnight', true)
            ->assertJsonPath('data.0.employee.registration_number', '7761')
            ->assertJsonPath('data.0.warnings', ['Lebih dari 4 jam lembur pada tanggal ini (5 jam)', 'Tanggal ini berkode L (Libur) di daftar hadir']);

        $this->getJson('/api/v1/overtime-entries?period=2026-10&type=lembur_dw')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.warnings', ['Tanggal ini belum berkode di daftar hadir']);

        $this->getJson('/api/v1/overtime-entries?period=2026-10&type=daftar_hadir')->assertUnprocessable();
    }

    public function test_employee_detail_lists_every_date_overtime_and_changes(): void
    {
        $this->terminate($this->ani, '2026-10-21');
        $this->storeCode($this->ani, '2026-10-01', 'K');
        $this->storeOvertime($this->ani, '2026-10-01', '17:00', '19:00');

        $response = $this->getJson("/api/v1/employees/{$this->ani->id}/attendance?period=2026-10")->assertOk();

        $days = $response->json('data.days');
        $this->assertCount(31, $days);
        $this->assertSame(['date' => '2026-10-01', 'status_code' => 'K', 'source' => 'import_excel', 'is_active' => true],
            array_intersect_key($days[0], array_flip(['date', 'status_code', 'source', 'is_active'])));
        $this->assertNull($days[1]['status_code']);
        $this->assertFalse($days[20]['is_active']);   // 21 Oktober: sudah nonaktif
        $response->assertJsonCount(1, 'data.overtime')->assertJsonPath('data.changes.0.action', 'Dibuat');
    }

    public function test_manual_correction_requires_reason_wins_over_import_and_is_recorded(): void
    {
        $this->storeCode($this->ani, '2026-10-05', 'K');
        $url = "/api/v1/employees/{$this->ani->id}/attendance";

        $this->putJson($url, ['date' => '2026-10-05', 'status_code' => 'S', 'reason' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'Alasan koreksi wajib diisi');

        $this->putJson($url, ['date' => '2026-10-05', 'status_code' => 'S', 'reason' => 'Surat dokter menyusul'])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'Diubah')
            ->assertJsonPath('data.days.4.status_code', 'S')
            ->assertJsonPath('data.days.4.source', 'manual')
            ->assertJsonPath('data.days.4.updated_by', 'Admin SDM');

        $change = AttendanceDayChange::where('action', 'Diubah')->sole();
        $this->assertSame(['manual', 'Surat dokter menyusul'], [$change->source->value, $change->reason]);
        $this->assertSame(['K', 'S'], [$change->items()->sole()->old_value, $change->items()->sole()->new_value]);

        // Impor Excel berikutnya tidak menimpa koreksi manual.
        $spreadsheet = $this->template('daftar_hadir');
        $this->setCodes($spreadsheet, $this->ani, [5 => 'K']);
        $this->import($spreadsheet, 'daftar_hadir')->assertCreated()->assertJsonPath('data.summary.conflicts', 1);
        $this->assertSame('S', AttendanceDay::sole()->status_code->value);

        // status_code null = kosongkan.
        $this->putJson($url, ['date' => '2026-10-05', 'status_code' => null, 'reason' => 'Salah input'])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'Dihapus');
        $this->assertSame(0, AttendanceDay::count());
    }

    public function test_manual_correction_rules(): void
    {
        $permanent = Employee::factory()->active()->create();
        $this->putJson("/api/v1/employees/{$permanent->id}/attendance", ['date' => '2026-10-05', 'status_code' => 'K', 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.employee_id.0', 'Koreksi absensi hanya untuk karyawan Casual & Daily Worker');

        $this->terminate($this->ani, '2026-10-10');
        $url = "/api/v1/employees/{$this->ani->id}/attendance";
        $this->putJson($url, ['date' => '2026-10-12', 'status_code' => 'K', 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'Karyawan tidak aktif pada tanggal 12/10/2026');
        $this->putJson($url, ['date' => '2026-11-02', 'status_code' => 'K', 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'Tanggal tidak boleh melewati bulan berjalan');
        $this->putJson($url, ['date' => '2026-10-05', 'status_code' => 'Z', 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status_code.0', 'Kode harus K, DW, I, S, M, C, atau L');

        PayrollPeriod::create(['period' => '2026-10', 'status' => PayrollPeriodStatus::Locked]);
        $this->putJson($url, ['date' => '2026-10-05', 'status_code' => 'K', 'reason' => 'x'])->assertStatus(423);
        $this->getJson("{$url}?period=2026-10")->assertOk();
        $this->assertSame(0, AttendanceDay::where('source', AttendanceSource::Manual)->count());
    }
}
