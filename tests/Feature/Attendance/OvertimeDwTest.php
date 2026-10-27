<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceSource;
use App\Enums\PayrollPeriodStatus;
use App\Models\AttendanceImportBatch;
use App\Models\Employee;
use App\Models\EmployeeWageScheme;
use App\Models\OvertimeEntry;
use App\Models\OvertimeEntryChange;
use App\Models\OvertimeSetting;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\Attendance\DwRecommender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian Lembur DW otomatis (Tahap 3): target & status, rekomendasi (pratinjau & simpan), dan penggantian
 * sumber dengan unggahan Excel. Hari ini 20 Oktober 2026; periode Oktober 2026 (1 Okt = Kamis).
 *
 * Skema uji: Gaji Pokok 2.600.000, HKE 26 -> Rp/hari 100.000; tarif/jam = round(2.600.000 / 173) = 15.029.
 * Upah per tanggal: 1 jam = 22.500 · 1,5 jam = 37.600 · 2 jam = 52.600 · 4 jam = 112.700; toleransi "sesuai" = 15.029.
 */
class OvertimeDwTest extends TestCase
{
    use AttendanceTestHelpers, RefreshDatabase;

    private Employee $ani;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 10:00:00');
        Sanctum::actingAs(User::factory()->create(['name' => 'Admin SDM']));
        OvertimeSetting::current()->update(['dw_default_description' => 'Membantu operasional wisata']);
        $this->ani = $this->casual('Ani', '7761');
        $this->scheme($this->ani);
    }

    // -------------------------------------------------------------------------
    // Target & status
    // -------------------------------------------------------------------------

    public function test_targets_compute_dw_days_target_pay_difference_status_and_sources(): void
    {
        $this->codes($this->ani, ['2026-10-01' => 'DW', '2026-10-02' => 'DW', '2026-10-05' => 'DW', '2026-10-06' => 'K']);
        $this->storeOvertime($this->ani, '2026-10-06', '17:00', '19:00', 'dw', 'manual');        // 2 jam = 52.600
        $this->storeOvertime($this->ani, '2026-10-07', '17:00', '18:00', 'dw', 'import_excel');  // 1 jam = 22.500
        $this->storeOvertime($this->ani, '2026-10-07', '20:00', '21:00', 'dw', 'import_excel');  // tanggal sama: 2 jam -> 52.600

        $noScheme = $this->casual('Budi', '7530');
        $this->codes($noScheme, ['2026-10-01' => 'DW']);
        $none = $this->casual('Cici', '8001');
        $this->scheme($none);
        $this->codes($none, ['2026-10-01' => 'DW']);
        $match = $this->casual('Dodi', '8002');
        $this->scheme($match);
        $this->codes($match, ['2026-10-01' => 'DW']);
        $this->storeOvertime($match, '2026-10-02', '17:00', '21:00', 'dw', 'manual');            // 4 jam = 112.700
        $this->casual('Euis', '8003');                                                           // tanpa DW: tidak tampil

        $rows = collect($this->getJson('/api/v1/overtime-dw/targets?period=2026-10')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(['Ani', 'Budi', 'Cici', 'Dodi'], $rows->keys()->all());
        $ani = $rows['Ani'];
        $this->assertSame([
            'wage_type' => 'Harian', 'dw_days' => 3, 'daily_rate' => 100_000, 'target_amount' => 300_000,
            'dw_entries' => 3, 'dw_minutes' => 240, 'dw_pay' => 105_200, 'difference' => -194_800, 'status' => 'kurang',
            'sources' => ['import_excel' => 2, 'rekomendasi_sistem' => 0, 'manual' => 1, 'mobile' => 0],
        ], array_intersect_key($ani, array_flip(['wage_type', 'dw_days', 'daily_rate', 'target_amount', 'dw_entries', 'dw_minutes', 'dw_pay', 'difference', 'status', 'sources'])));
        $this->assertSame('skema_belum_diatur', $rows['Budi']['status']);
        $this->assertNull($rows['Budi']['daily_rate']);
        $this->assertSame('belum_ada', $rows['Cici']['status']);
        $this->assertSame(['dw_pay' => 112_700, 'difference' => 12_700, 'status' => 'sesuai'],
            array_intersect_key($rows['Dodi'], array_flip(['dw_pay', 'difference', 'status'])));
    }

    // -------------------------------------------------------------------------
    // Pratinjau rekomendasi
    // -------------------------------------------------------------------------

    public function test_preview_uses_only_candidate_dates_in_30_minute_steps_from_1700_and_reaches_target(): void
    {
        $this->codesForRange($this->ani, 1, 31, 'K');
        $this->codes($this->ani, ['2026-10-01' => 'DW', '2026-10-08' => 'DW', '2026-10-15' => 'DW', '2026-10-22' => 'DW', '2026-10-29' => 'DW', '2026-10-04' => 'L']);
        $this->storeOvertime($this->ani, '2026-10-02', '17:00', '18:00');                         // reguler: tanggal tidak dipakai
        $this->storeOvertime($this->ani, '2026-10-03', '17:00', '19:00', 'dw', 'manual');         // dipertahankan, 52.600
        $this->storeOvertime($this->ani, '2026-10-05', '17:00', '18:00', 'dw', 'import_excel');   // akan diganti

        foreach (range(1, 5) as $attempt) {   // acak: ulangi beberapa kali
            $row = $this->previewRow();

            $this->assertNull($row['skipped_reason']);
            $this->assertSame(['dw_days' => 5, 'daily_rate' => 100_000, 'target_amount' => 500_000, 'kept_entries' => 1, 'kept_pay' => 52_600, 'replaces_entries' => 1],
                array_intersect_key($row, array_flip(['dw_days', 'daily_rate', 'target_amount', 'kept_entries', 'kept_pay', 'replaces_entries'])));
            $this->assertSame('sesuai', $row['status'], "percobaan {$attempt}: selisih {$row['difference']}");
            $this->assertSame($row['result_pay'] - 500_000, $row['difference']);
            $this->assertSame(52_600 + array_sum(array_column($row['entries'], 'pay')), $row['result_pay']);

            foreach ($row['entries'] as $entry) {
                $this->assertNotContains($entry['date'], ['2026-10-02', '2026-10-03', '2026-10-04']);
                $this->assertSame('17:00', $entry['start_time']);
                $this->assertSame(0, $entry['duration_minutes'] % 30);
                $this->assertLessThanOrEqual(240, $entry['duration_minutes']);
                $this->assertSame(Carbon::parse('17:00')->addMinutes($entry['duration_minutes'])->format('H:i'), $entry['end_time']);
            }
        }
    }

    public function test_preview_respects_daily_and_weekly_limits_and_notes_when_dates_run_out(): void
    {
        // Semua tanggal DW -> target 3.100.000, tidak mungkin tercapai dengan batas 4 jam/hari & 18 jam/minggu.
        $this->codesForRange($this->ani, 1, 31, 'DW');
        // Minggu 28 Sep–4 Okt: lembur reguler 4 jam di bulan September ikut dihitung batas mingguan.
        $this->storeOvertime($this->ani, '2026-09-28', '17:00', '21:00');
        $this->storeOvertime($this->ani, '2026-09-29', '17:00', '21:00');

        $row = $this->previewRow();

        $this->assertContains(DwRecommender::NOTE_NOT_ENOUGH, $row['notes']);
        $this->assertSame('kurang', $row['status']);
        $perWeek = [];
        foreach ($row['entries'] as $entry) {
            $this->assertLessThanOrEqual(4 * 60, $entry['duration_minutes']);
            $week = Carbon::parse($entry['date'])->startOfWeek(Carbon::MONDAY)->toDateString();
            $perWeek[$week] = ($perWeek[$week] ?? 0) + $entry['duration_minutes'];
        }
        $perWeek['2026-09-28'] = ($perWeek['2026-09-28'] ?? 0) + 8 * 60;   // lembur reguler September
        foreach ($perWeek as $week => $minutes) {
            $this->assertLessThanOrEqual(18 * 60, $minutes, "minggu {$week}");
        }
        // Target tak tercapai -> semua sisa batas terpakai: minggu itu 8 jam reguler + 10 jam rekomendasi = 18 jam.
        $this->assertSame(18 * 60, $perWeek['2026-09-28']);
    }

    public function test_preview_kept_enough_and_skipped_employees(): void
    {
        $this->codes($this->ani, ['2026-10-01' => 'DW', '2026-10-02' => 'K']);
        $this->storeOvertime($this->ani, '2026-10-02', '17:00', '21:00', 'dw', 'mobile');   // 112.700 ≥ target 100.000
        $noScheme = $this->casual('Budi', '7530');
        $this->codes($noScheme, ['2026-10-01' => 'DW']);
        $noDays = $this->casual('Cici', '8001');
        $this->scheme($noDays);

        $response = $this->postJson('/api/v1/overtime-dw/recommendations/preview', [
            'period' => '2026-10', 'employee_ids' => [$this->ani->id, $noScheme->id, $noDays->id],
        ])->assertOk();
        $rows = collect($response->json('data.employees'))->keyBy('name');

        $this->assertSame([], $rows['Ani']['entries']);
        $this->assertSame([DwRecommender::NOTE_KEPT_ENOUGH], $rows['Ani']['notes']);
        $this->assertSame(['kept_pay' => 112_700, 'result_pay' => 112_700, 'status' => 'sesuai'], array_intersect_key($rows['Ani'], array_flip(['kept_pay', 'result_pay', 'status'])));
        $this->assertSame('Skema upah belum diatur', $rows['Budi']['skipped_reason']);
        $this->assertSame('Tidak ada hari DW di daftar hadir', $rows['Cici']['skipped_reason']);
        $response->assertJsonPath('data.summary.employees', 1)->assertJsonPath('data.summary.skipped', 2);
    }

    // -------------------------------------------------------------------------
    // Simpan rekomendasi
    // -------------------------------------------------------------------------

    public function test_store_replaces_excel_and_old_recommendations_of_that_employee_only(): void
    {
        $this->codesForRange($this->ani, 1, 10, 'DW');
        $batch = AttendanceImportBatch::create(['period' => '2026-10', 'import_type' => 'lembur_dw', 'file_name' => 'dw.xlsx', 'status' => 'aktif']);
        $excel = $this->storeOvertime($this->ani, '2026-10-01', '17:00', '18:00', 'dw', 'import_excel');
        $excel->update(['import_batch_id' => $batch->id]);
        $this->storeOvertime($this->ani, '2026-10-02', '17:00', '18:00', 'dw', 'rekomendasi_sistem');
        $manual = $this->storeOvertime($this->ani, '2026-10-03', '17:00', '18:00', 'dw', 'manual');
        $budi = $this->casual('Budi', '7530');
        $this->scheme($budi);
        $other = $this->storeOvertime($budi, '2026-10-01', '17:00', '18:00', 'dw', 'rekomendasi_sistem');

        $entries = $this->previewRow()['entries'];
        $payload = array_map(fn (array $entry) => array_intersect_key($entry, array_flip(['date', 'start_time', 'end_time'])), $entries);

        $this->postJson('/api/v1/overtime-dw/recommendations', ['period' => '2026-10', 'employees' => [['employee_id' => $this->ani->id, 'entries' => $payload]]])
            ->assertCreated()
            ->assertJsonPath('data', ['saved_employees' => 1, 'created_count' => count($payload), 'deleted_count' => 2]);

        $aniEntries = OvertimeEntry::where('employee_id', $this->ani->id)->get();
        $this->assertTrue($aniEntries->contains('id', $manual->id));
        $this->assertSame(count($payload), $aniEntries->where('source.value', 'rekomendasi_sistem')->count());
        $this->assertSame(0, $aniEntries->where('source.value', 'import_excel')->count());
        $this->assertSame('Membantu operasional wisata', $aniEntries->firstWhere('source.value', 'rekomendasi_sistem')?->description);
        $this->assertTrue(OvertimeEntry::whereKey($other->id)->exists());   // karyawan lain tidak disentuh

        $this->assertSame('diganti', $batch->fresh()->status->value);   // semua baris Excel aktif sudah terhapus
        $this->assertSame(count($payload), OvertimeEntryChange::where('action', 'Rekomendasi sistem')->count());
        $deleted = OvertimeEntryChange::where('action', 'Dihapus')->get();
        $this->assertCount(2, $deleted);
        $this->assertTrue($deleted->every(fn (OvertimeEntryChange $change) => $change->overtime_entry_id === null && $change->reason === 'Diganti rekomendasi sistem'));
    }

    public function test_store_revalidates_dates_overlaps_and_limits(): void
    {
        $this->codesForRange($this->ani, 1, 10, 'K');
        $this->codes($this->ani, ['2026-10-04' => 'L']);
        $this->storeOvertime($this->ani, '2026-10-02', '17:00', '18:00');                    // reguler
        $this->storeOvertime($this->ani, '2026-10-03', '18:00', '19:00', 'dw', 'manual');   // DW manual

        $errors = $this->postJson('/api/v1/overtime-dw/recommendations', ['period' => '2026-10', 'employees' => [[
            'employee_id' => $this->ani->id,
            'entries' => [
                ['date' => '2026-10-02', 'start_time' => '17:00', 'end_time' => '18:00'],   // 0: ada lembur reguler
                ['date' => '2026-10-03', 'start_time' => '17:00', 'end_time' => '18:00'],   // 1: ada DW manual
                ['date' => '2026-10-04', 'start_time' => '17:00', 'end_time' => '18:00'],   // 2: kode L
                ['date' => '2026-10-05', 'start_time' => '17:00', 'end_time' => '22:00'],   // 3: 5 jam > 4 jam
                ['date' => '2026-10-06', 'start_time' => '17:00', 'end_time' => '19:00'],   // 4: benar
                ['date' => '2026-10-06', 'start_time' => '18:00', 'end_time' => '18:30'],   // 5: tumpang tindih baris 4
            ],
        ]]])->assertUnprocessable()->json('errors');

        $this->assertSame(['Ani, 02/10/2026: Tanggal ini sudah ada lembur reguler'], $errors['employees.0.entries.0']);
        $this->assertSame(['Ani, 03/10/2026: Tanggal ini sudah ada lembur DW manual/mobile'], $errors['employees.0.entries.1']);
        $this->assertSame(['Ani, 04/10/2026: Tanggal ini berkode L (harus K atau DW)'], $errors['employees.0.entries.2']);
        $this->assertSame(['Ani, 05/10/2026: lebih dari 4 jam lembur pada tanggal ini'], $errors['employees.0.entries.3']);
        $this->assertArrayNotHasKey('employees.0.entries.4', $errors);
        $this->assertSame(['Ani, 06/10/2026: tumpang tindih dengan 17:00–19:00'], $errors['employees.0.entries.5']);
        $this->assertSame(0, OvertimeEntry::where('source', 'rekomendasi_sistem')->count());
    }

    public function test_store_requires_default_description_and_locked_period_rejects_everything(): void
    {
        $this->codes($this->ani, ['2026-10-01' => 'DW']);
        $payload = ['period' => '2026-10', 'employees' => [['employee_id' => $this->ani->id, 'entries' => []]]];

        OvertimeSetting::current()->update(['dw_default_description' => null]);
        $this->postJson('/api/v1/overtime-dw/recommendations', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.period.0', 'Uraian Pekerjaan bawaan Lembur DW belum diisi di Pengaturan Lembur');

        PayrollPeriod::create(['period' => '2026-10', 'status' => PayrollPeriodStatus::Locked]);
        $message = 'Periode upah Oktober 2026 sudah dikunci. Lembur bulan itu tidak bisa diubah.';
        $this->postJson('/api/v1/overtime-dw/recommendations', $payload)->assertUnprocessable()->assertJsonPath('errors.period.0', $message);
        $this->postJson('/api/v1/overtime-dw/recommendations/preview', ['period' => '2026-10'])->assertUnprocessable()->assertJsonPath('errors.period.0', $message);
        $this->getJson('/api/v1/overtime-dw/targets?period=2026-10')->assertOk();
    }

    // -------------------------------------------------------------------------
    // Unggah Excel Lembur DW menggantikan rekomendasi
    // -------------------------------------------------------------------------

    public function test_excel_dw_upload_replaces_all_recommendations_of_the_period(): void
    {
        $this->codesForRange($this->ani, 1, 10, 'K');
        $budi = $this->casual('Budi', '7530');
        $this->codesForRange($budi, 1, 10, 'K');
        $this->storeOvertime($this->ani, '2026-10-01', '17:00', '18:00', 'dw', 'rekomendasi_sistem');
        $this->storeOvertime($budi, '2026-10-02', '17:00', '18:00', 'dw', 'rekomendasi_sistem');   // tidak ada di file
        $manual = $this->storeOvertime($budi, '2026-10-03', '17:00', '18:00', 'dw', 'manual');
        $regular = $this->storeOvertime($budi, '2026-10-04', '17:00', '18:00', 'reguler', 'import_excel');

        $spreadsheet = $this->template('lembur_dw');
        $sheet = $spreadsheet->getSheet(0);
        for ($row = 5; $row <= 8; $row++) {   // kosongkan isi template (baris tersimpan)
            foreach (['B', 'L', 'M', 'N', 'P'] as $column) {
                $sheet->setCellValue("{$column}{$row}", null);
            }
        }
        $this->setOvertimeRows($spreadsheet, 'lembur_dw', [['7761', '05/10/2026', '17:00', '18:00', 'Pelayanan tamu']]);

        $this->preview($spreadsheet, 'lembur_dw')->assertOk()->assertJsonPath('data.summary.will_delete', 2);
        $this->import($spreadsheet, 'lembur_dw')->assertCreated()->assertJsonPath('data.summary.deleted', 2)->assertJsonPath('data.batch.deleted_count', 2);

        $this->assertSame(0, OvertimeEntry::where('source', 'rekomendasi_sistem')->count());
        $this->assertTrue(OvertimeEntry::whereKey($manual->id)->exists());
        $this->assertTrue(OvertimeEntry::whereKey($regular->id)->exists());
        $this->assertSame(1, OvertimeEntry::where('source', 'import_excel')->where('overtime_type', 'dw')->count());
    }

    public function test_import_index_shows_entry_count_of_all_sources(): void
    {
        $budi = $this->casual('Budi', '7530');
        $this->codes($this->ani, ['2026-10-01' => 'K', '2026-10-02' => 'K']);
        $this->storeCode($budi, '2026-10-01', 'S', AttendanceSource::Manual);
        $this->storeOvertime($this->ani, '2026-10-01', '17:00', '18:00', 'reguler', 'manual');
        $this->storeOvertime($this->ani, '2026-10-02', '17:00', '18:00', 'dw', 'rekomendasi_sistem');
        $this->storeOvertime($budi, '2026-10-02', '17:00', '18:00', 'dw', 'mobile');

        $this->getJson('/api/v1/attendance-imports?period=2026-10')
            ->assertOk()
            ->assertJsonPath('data.sections.0.entry_count', 2)
            ->assertJsonPath('data.sections.1.entry_count', 1)
            ->assertJsonPath('data.sections.2.entry_count', 2);
    }

    // -------------------------------------------------------------------------
    // Bantuan
    // -------------------------------------------------------------------------

    private function scheme(Employee $employee): void
    {
        EmployeeWageScheme::create([
            'employee_id' => $employee->id, 'wage_type' => 'Harian', 'base_salary' => 2_600_000, 'working_days' => 26, 'effective_date' => '2026-01-01',
        ]);
    }

    /** @param  array<string, string>  $codes  [tanggal => kode] */
    private function codes(Employee $employee, array $codes): void
    {
        foreach ($codes as $date => $code) {
            $this->storeCode($employee, $date, $code);
        }
    }

    private function codesForRange(Employee $employee, int $from, int $to, string $code): void
    {
        $this->codes($employee, collect(range($from, $to))->mapWithKeys(fn (int $day) => [sprintf('2026-10-%02d', $day) => $code])->all());
    }

    /** Baris pratinjau Ani. */
    private function previewRow(): array
    {
        return collect($this->postJson('/api/v1/overtime-dw/recommendations/preview', ['period' => '2026-10', 'employee_ids' => [$this->ani->id]])
            ->assertOk()->json('data.employees'))->first();
    }
}
