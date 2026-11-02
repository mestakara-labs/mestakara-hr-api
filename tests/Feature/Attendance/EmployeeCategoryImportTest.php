<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\EmployeeWageScheme;
use App\Models\OvertimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian pemisahan Unggah Absensi & lembur per KATEGORI KARYAWAN (employee_category=casual|daily_worker):
 * parameter wajib, tab per kategori, template & unggah ulang hanya kategori itu, karyawan kategori lain di file = salah,
 * Lembur DW & kode DW hanya untuk Casual. Hari ini 20 Oktober 2026; periode Oktober 2026.
 */
class EmployeeCategoryImportTest extends TestCase
{
    use AttendanceTestHelpers, RefreshDatabase;

    private Employee $ani;

    private Employee $dedi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 10:00:00');
        Sanctum::actingAs(User::factory()->create(['name' => 'Admin SDM']));
        $this->ani = $this->casual('Ani', '7761');
        $this->dedi = $this->casual('Dedi', '7530', 'Karyawan Daily Worker');
    }

    public function test_category_is_required_and_must_be_valid(): void
    {
        $message = 'Kategori karyawan harus Casual atau Daily Worker';
        $urls = [
            '/api/v1/attendance-imports?period=2026-10',
            '/api/v1/attendance-imports/template?period=2026-10&type=daftar_hadir',
            '/api/v1/attendance-summaries?period=2026-10',
            '/api/v1/overtime-entries?period=2026-10&type=lembur_reguler',
        ];
        foreach ($urls as $url) {
            $this->getJson($url)->assertUnprocessable()->assertJsonPath('errors.employee_category.0', $message);
            $this->getJson("{$url}&employee_category=pkwt")->assertUnprocessable()->assertJsonPath('errors.employee_category.0', $message);
        }
        $this->postJson('/api/v1/attendance-imports/preview', ['file' => $this->upload($this->template('daftar_hadir')), 'period' => '2026-10', 'type' => 'daftar_hadir'])
            ->assertUnprocessable()->assertJsonPath('errors.employee_category.0', $message);
    }

    public function test_lembur_dw_is_only_for_casual(): void
    {
        $message = 'Lembur DW hanya untuk karyawan Casual';
        $this->getJson('/api/v1/attendance-imports/template?period=2026-10&type=lembur_dw&employee_category=daily_worker')
            ->assertUnprocessable()->assertJsonPath('errors.employee_category.0', $message);
        $this->getJson('/api/v1/overtime-entries?period=2026-10&type=lembur_dw&employee_category=daily_worker')
            ->assertUnprocessable()->assertJsonPath('errors.employee_category.0', $message);
        $this->postJson('/api/v1/attendance-imports', ['file' => $this->upload($this->template('lembur_dw')), 'period' => '2026-10', 'type' => 'lembur_dw', 'employee_category' => 'daily_worker'])
            ->assertUnprocessable()->assertJsonPath('errors.employee_category.0', $message);
    }

    public function test_index_has_sections_entry_counts_and_batches_per_category(): void
    {
        $this->storeCode($this->ani, '2026-10-01', 'K');
        $this->storeCode($this->dedi, '2026-10-01', 'K');
        $this->storeOvertime($this->dedi, '2026-10-01', '17:00', '18:00');
        $casualFile = $this->template('daftar_hadir');
        $this->setCodes($casualFile, $this->ani, [2 => 'K']);
        $this->import($casualFile, 'daftar_hadir')->assertCreated()->assertJsonPath('data.batch.employee_category', 'casual');

        $casual = $this->getJson('/api/v1/attendance-imports?period=2026-10&employee_category=casual')->assertOk()->json('data');
        $this->assertSame('casual', $casual['employee_category']);
        $this->assertSame(['daftar_hadir', 'lembur_reguler', 'lembur_dw'], array_column($casual['sections'], 'import_type'));
        $this->assertSame([1, 0, 0], array_column($casual['sections'], 'entry_count'));
        $this->assertCount(1, $casual['sections'][0]['batches']);

        $dailyWorker = $this->getJson('/api/v1/attendance-imports?period=2026-10&employee_category=daily_worker')->assertOk()->json('data');
        $this->assertSame(['daftar_hadir', 'lembur_reguler'], array_column($dailyWorker['sections'], 'import_type'));
        $this->assertSame([1, 1], array_column($dailyWorker['sections'], 'entry_count'));
        $this->assertSame([], $dailyWorker['sections'][0]['batches']);   // batch Casual tidak tampil di Daily Worker
    }

    public function test_template_has_only_employees_of_the_category(): void
    {
        $response = $this->get('/api/v1/attendance-imports/template?period=2026-10&type=daftar_hadir&employee_category=daily_worker')->assertOk();
        $this->assertStringContainsString(rawurlencode('Template Daftar Hadir Karyawan Daily Worker - Oktober 2026.xlsx'), $response->headers->get('Content-Disposition'));

        $sheet = $this->template('daftar_hadir', '2026-10', 'daily_worker')->getSheetByName('Daftar Hadir');
        $this->assertSame('DAFTAR HADIR KARYAWAN DAILY WORKER — UNIT AGROWISATA — Periode: Oktober 2026', $sheet->getCell('A1')->getValue());
        $this->assertSame('LEMBUR REGULER KARYAWAN DAILY WORKER — UNIT AGROWISATA — Periode: Oktober 2026',
            $this->template('lembur_reguler', '2026-10', 'daily_worker')->getSheet(0)->getCell('A1')->getValue());
        $this->attendanceRow($sheet, $this->dedi);
        $names = array_filter(array_column($sheet->toArray(), 2));
        $this->assertNotContains('Ani', $names);
    }

    public function test_employee_of_other_category_in_file_is_invalid(): void
    {
        $casualFile = $this->template('daftar_hadir');
        $this->assertSame(['No. Registrasi' => 'Karyawan Casual — unggah di tab Karyawan Casual'],
            $this->messages($this->previewRow($this->preview($casualFile, 'daftar_hadir', '2026-10', 'daily_worker')->assertOk(), $this->ani)));

        $dailyWorkerFile = $this->template('daftar_hadir', '2026-10', 'daily_worker');
        $this->assertSame(['No. Registrasi' => 'Karyawan Daily Worker — unggah di tab Karyawan Daily Worker'],
            $this->messages($this->previewRow($this->preview($dailyWorkerFile, 'daftar_hadir')->assertOk(), $this->dedi)));

        // Lembur DW (tab Casual) berisi karyawan Daily Worker.
        $overtime = $this->template('lembur_dw');
        $this->setOvertimeRows($overtime, 'lembur_dw', [['7530', '01/10/2026', '17:00', '18:00', 'Persiapan acara']]);
        $row = $this->preview($overtime, 'lembur_dw')->assertOk()->json('data.rows.0');
        $this->assertSame(['No. Registrasi' => 'Lembur DW hanya untuk karyawan Casual'], $this->messages($row));
    }

    public function test_daily_worker_attendance_has_no_dw_code_and_no_day_cap(): void
    {
        EmployeeWageScheme::create([
            'employee_id' => $this->dedi->id, 'wage_type' => 'Harian', 'base_salary' => 2_500_000, 'working_days' => 25,
            'is_capped' => true, 'cap_days' => 20, 'effective_date' => '2026-01-01',
        ]);
        $file = $this->template('daftar_hadir', '2026-10', 'daily_worker');
        $codes = array_fill_keys(range(1, 19), 'K');
        $this->setCodes($file, $this->dedi, [...$codes, 20 => 'DW']);

        $row = $this->previewRow($this->preview($file, 'daftar_hadir', '2026-10', 'daily_worker')->assertOk(), $this->dedi);
        $this->assertSame(['Tgl 20' => 'Kode DW hanya untuk karyawan Casual'], $this->messages($row));

        // 20 K + 2 K = 22 hari K: Daily Worker tidak punya batas hari kerja -> tanpa peringatan.
        $this->setCodes($file, $this->dedi, [20 => 'K', 21 => 'K', 22 => 'K']);
        $row = $this->previewRow($this->preview($file, 'daftar_hadir', '2026-10', 'daily_worker')->assertOk(), $this->dedi);
        $this->assertSame(['benar', []], [$row['status'], $row['warnings']]);
    }

    public function test_reupload_only_replaces_data_of_that_category(): void
    {
        $casualFile = $this->template('daftar_hadir');
        $this->setCodes($casualFile, $this->ani, [1 => 'K']);
        $this->import($casualFile, 'daftar_hadir')->assertCreated();
        $dailyWorkerFile = $this->template('daftar_hadir', '2026-10', 'daily_worker');
        $this->setCodes($dailyWorkerFile, $this->dedi, [1 => 'K']);
        $this->import($dailyWorkerFile, 'daftar_hadir', '2026-10', 'daily_worker')->assertCreated();

        // Unggah ulang Casual tanpa kode: data unggahan Ani dikosongkan, Dedi (Daily Worker) tetap.
        $this->setCodes($casualFile, $this->ani, [1 => null]);
        $this->import($casualFile, 'daftar_hadir')->assertCreated();
        $this->assertFalse(AttendanceDay::query()->where('employee_id', $this->ani->id)->exists());
        $this->assertTrue(AttendanceDay::query()->where('employee_id', $this->dedi->id)->exists());

        // Lembur Reguler: unggahan Daily Worker tidak menghapus lembur Excel milik Casual.
        $casualOvertime = $this->template('lembur_reguler');
        $this->setOvertimeRows($casualOvertime, 'lembur_reguler', [['7761', '02/10/2026', '17:00', '18:00', 'Persiapan acara']]);
        $this->import($casualOvertime, 'lembur_reguler')->assertCreated();
        $dailyWorkerOvertime = $this->template('lembur_reguler', '2026-10', 'daily_worker');
        $this->setOvertimeRows($dailyWorkerOvertime, 'lembur_reguler', [['7530', '02/10/2026', '17:00', '18:00', 'Persiapan acara']]);
        $this->import($dailyWorkerOvertime, 'lembur_reguler', '2026-10', 'daily_worker')->assertCreated()->assertJsonPath('data.summary.deleted', 0);

        $this->assertSame(['Ani', 'Dedi'], OvertimeEntry::query()->with('employee')->get()->pluck('employee.name')->sort()->values()->all());
    }

    public function test_summaries_and_overtime_list_per_category(): void
    {
        $this->storeOvertime($this->ani, '2026-10-02', '17:00', '19:00');
        $this->storeOvertime($this->dedi, '2026-10-02', '17:00', '18:00');

        $dailyWorker = $this->getJson('/api/v1/attendance-summaries?period=2026-10&employee_category=daily_worker')->assertOk()->json('data');
        $this->assertSame(['Dedi'], array_column($dailyWorker, 'name'));
        $this->assertSame(60, $dailyWorker[0]['regular_overtime']['minutes']);
        $this->assertSame(0, $dailyWorker[0]['dw_overtime']['minutes']);

        $casual = $this->getJson('/api/v1/overtime-entries?period=2026-10&type=lembur_reguler&employee_category=casual')->assertOk()->json('data');
        $this->assertSame(['Ani'], array_column(array_column($casual, 'employee'), 'name'));
    }

    public function test_manual_dw_code_and_dw_overtime_are_rejected_for_daily_worker(): void
    {
        $this->putJson("/api/v1/employees/{$this->dedi->id}/attendance", ['date' => '2026-10-05', 'status_code' => 'DW', 'reason' => 'Koreksi'])
            ->assertUnprocessable()->assertJsonPath('errors.status_code.0', 'Kode DW hanya untuk karyawan Casual');
        $this->putJson("/api/v1/employees/{$this->dedi->id}/attendance", ['date' => '2026-10-05', 'status_code' => 'K', 'reason' => 'Koreksi'])->assertOk();

        $overtime = ['period' => '2026-10', 'employee_id' => $this->dedi->id, 'date' => '2026-10-05', 'start_time' => '17:00', 'end_time' => '18:00',
            'description' => 'Persiapan acara', 'reason' => 'Lembur susulan'];
        $this->postJson('/api/v1/overtime-entries', [...$overtime, 'overtime_type' => 'dw'])
            ->assertUnprocessable()->assertJsonPath('errors.employee_id.0', 'Lembur DW hanya untuk karyawan Casual');
        $this->postJson('/api/v1/overtime-entries', [...$overtime, 'overtime_type' => 'reguler'])->assertCreated();
    }

    public function test_dw_recommendation_skips_daily_worker(): void
    {
        $this->storeCode($this->dedi, '2026-10-01', 'K');

        $rows = collect($this->postJson('/api/v1/overtime-dw/recommendations/preview', ['period' => '2026-10', 'employee_ids' => [$this->dedi->id]])
            ->assertOk()->json('data.employees'))->keyBy('name');

        $this->assertSame('Lembur DW hanya untuk karyawan Casual', $rows['Dedi']['skipped_reason']);
        $this->assertSame([], $this->getJson('/api/v1/overtime-dw/targets?period=2026-10')->assertOk()->json('data'));
    }
}
