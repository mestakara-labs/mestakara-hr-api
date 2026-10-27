<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceSource;
use App\Enums\PayrollPeriodStatus;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayChange;
use App\Models\AttendanceImportBatch;
use App\Models\AttendanceImportConflict;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\EmployeeWageScheme;
use App\Models\Holiday;
use App\Models\OvertimeEntry;
use App\Models\PayrollGroup;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\User;
use App\Services\Attendance\AttendanceListLayout;
use App\Services\Attendance\AttendanceListTemplateWorkbook;
use App\Services\Attendance\OvertimeLayout;
use App\Services\Employee\EmployeeExportWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * Pengujian Unggah Absensi: template, pratinjau, dan simpan (daftar hadir, lembur reguler, lembur DW).
 * Hari ini 20 Oktober 2026; periode uji Oktober 2026 (1 Oktober = Kamis, 4 Oktober = Minggu).
 */
class AttendanceImportTest extends TestCase
{
    use AttendanceTestHelpers, RefreshDatabase;

    private Employee $ani;

    private Employee $budi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 10:00:00');
        Sanctum::actingAs(User::factory()->create(['name' => 'Admin SDM']));
        $this->ani = $this->casual('Ani', '7761');
        $this->budi = $this->casual('Budi', '7530', 'Karyawan Daily Worker');
    }

    // -------------------------------------------------------------------------
    // Template
    // -------------------------------------------------------------------------

    public function test_attendance_template_layout_dropdown_shading_and_saved_codes(): void
    {
        Holiday::create(['date' => '2026-10-17', 'name' => 'Libur Perusahaan', 'type' => 'Libur Perusahaan']);
        $this->terminate($this->budi, '2026-10-15');
        $this->storeCode($this->ani, '2026-10-01', 'K');
        $this->storeCode($this->ani, '2026-10-02', 'S', AttendanceSource::Manual);
        Employee::factory()->active()->create(['name' => 'Tetap']);   // bukan Casual/DW

        // Jabatan, kelompok upah, & cost center Ani yang berlaku.
        PositionAssignment::factory()->for($this->ani)->for(Position::factory()->state(['title' => 'Karyawan Parkir']))->create(['start_date' => '2026-01-01']);
        EmployeeWageScheme::create([
            'employee_id' => $this->ani->id, 'wage_type' => 'Harian', 'base_salary' => 2_375_000, 'effective_date' => '2026-01-01',
            'payroll_group_id' => PayrollGroup::factory()->create(['name' => 'Petugas Parkir'])->id,
        ]);
        $this->ani->costCenterHistories()->create([
            'cost_center_id' => CostCenter::factory()->create(['code' => '4A44AGR007', 'name' => 'Tiket & Parkir'])->id,
            'effective_date' => '2026-01-01',
        ]);

        $name = 'Template Daftar Hadir - Oktober 2026.xlsx';
        $response = $this->get('/api/v1/attendance-imports/template?period=2026-10&type=daftar_hadir')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$name.'"; filename*=UTF-8\'\''.rawurlencode($name));
        $spreadsheet = $this->spreadsheetOf($response);
        $sheet = $spreadsheet->getSheetByName('Daftar Hadir');
        $date = fn (int $day, int $row) => AttendanceListLayout::dateLetter($day).$row;

        $this->assertSame(['Daftar Hadir', 'Petunjuk', 'Daftar'], $spreadsheet->getSheetNames());
        $this->assertStringContainsString('Periode: Oktober 2026', $sheet->getCell('A1')->getValue());
        $this->assertSame(
            ['No', 'No. Registrasi', 'Nama', 'Jabatan', 'Status', 'Sub Unit', 'Kode Afd', 'Objek Penempatan', 'Kelompok Upah', 'Cost Center', 'Uraian Cost Center'],
            array_map(fn (string $column) => $sheet->getCell("{$column}2")->getValue(), ['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L']),
        );
        $this->assertSame(1, $sheet->getCell($date(1, 2))->getValue());
        $this->assertSame('Kam', $sheet->getCell($date(1, 3))->getValue());
        $this->assertSame(31, $sheet->getCell($date(31, 2))->getValue());
        $this->assertSame('K', $sheet->getCell(AttendanceListLayout::countLetter(31, 0).'2')->getValue());

        // Baris 4 = baris filter abu-abu berisi salinan judul kolom (supaya urut cepat Excel tidak menganggapnya data).
        $this->assertSame(EmployeeExportWorkbook::FILTER_GREY, $sheet->getStyle('D4')->getFill()->getStartColor()->getRGB());
        $this->assertFilterRowAllowsFilterAndSort($sheet, 'D4');
        $this->assertSame(['ID Sistem', 'Nama', '1', 'K'], [
            $sheet->getCell('A4')->getValue(), $sheet->getCell('D4')->getValue(),
            $sheet->getCell($date(1, 4))->getValue(), $sheet->getCell(AttendanceListLayout::countLetter(31, 0).'4')->getValue(),
        ]);
        $this->assertSame('A4:'.AttendanceListLayout::countLetter(31, 6).'6', $sheet->getAutoFilter()->getRange());
        $this->assertSame('F5', $sheet->getFreezePane());   // judul & kolom sampai Jabatan dibekukan

        // Warna kode: K hijau, DW kuning, C/I/S oranye, M/L merah.
        $colors = collect($sheet->getConditionalStyles($date(1, 5).':'.$date(31, 6)))
            ->mapWithKeys(fn ($condition) => [trim($condition->getConditions()[0], '"') => $condition->getStyle()->getFill()->getEndColor()->getRGB()])
            ->all();
        $this->assertSame(['K' => 'C6EFCE', 'DW' => 'FFEB9C', 'C' => 'F4B084', 'I' => 'F4B084', 'S' => 'F4B084', 'M' => 'FFC7CE', 'L' => 'FFC7CE'], $colors);

        // Hanya Casual & DW, urut nama; identitas lengkap; sudah terisi kode tersimpan (sumber apa pun).
        $this->assertSame([$this->ani->id, $this->budi->id], [$sheet->getCell('A5')->getValue(), $sheet->getCell('A6')->getValue()]);
        $this->assertSame(['Karyawan Parkir', 'Petugas Parkir', '4A44AGR007', 'Tiket & Parkir'],
            array_map(fn (string $column) => $sheet->getCell("{$column}5")->getValue(), ['E', 'J', 'K', 'L']));
        $this->assertNull($sheet->getCell('J6')->getValue());   // Budi belum punya skema upah
        $this->assertSame('K', $sheet->getCell($date(1, 5))->getValue());
        $this->assertSame('S', $sheet->getCell($date(2, 5))->getValue());
        $this->assertSame('=COUNTIF('.$date(1, 5).':'.$date(31, 5).',"K")', $sheet->getCell(AttendanceListLayout::countLetter(31, 0).'5')->getValue());

        // Minggu (4 Okt) & hari libur (17 Okt) diarsir; tanggal tidak aktif abu-abu.
        $this->assertSame(AttendanceListTemplateWorkbook::HOLIDAY_FILL, $sheet->getStyle($date(4, 5))->getFill()->getStartColor()->getRGB());
        $this->assertSame(AttendanceListTemplateWorkbook::HOLIDAY_FILL, $sheet->getStyle($date(17, 5))->getFill()->getStartColor()->getRGB());
        $this->assertSame(AttendanceListTemplateWorkbook::INACTIVE_FILL, $sheet->getStyle($date(15, 6))->getFill()->getStartColor()->getRGB());

        // Dropdown kode & proteksi: judul dikunci, baris data tidak dikunci (supaya bisa diurutkan).
        $validation = $sheet->getDataValidationCollection()[$date(1, 5).':'.$date(31, 6)];
        $this->assertSame("'Daftar'!\$A\$2:\$A\$8", $validation->getFormula1());
        $this->assertTrue($sheet->getProtection()->getSheet());
        $this->assertSame('unprotected', $sheet->getStyle($date(1, 5))->getProtection()->getLocked());
        $this->assertSame('unprotected', $sheet->getStyle('D5')->getProtection()->getLocked());
        $this->assertNotSame('unprotected', $sheet->getStyle('D2')->getProtection()->getLocked());
    }

    public function test_overtime_template_with_many_saved_entries_is_written_without_server_side_calculation(): void
    {
        $rows = [];
        foreach (range(1, 31) as $day) {
            foreach (range(0, 19) as $slot) {
                $start = sprintf('%02d:%02d', intdiv($slot * 30, 60), $slot * 30 % 60);
                $end = sprintf('%02d:%02d', intdiv($slot * 30 + 30, 60), ($slot * 30 + 30) % 60);
                $rows[] = [
                    'id' => (string) str()->uuid7(), 'employee_id' => $this->ani->id, 'date' => sprintf('2026-10-%02d', $day),
                    'start_time' => $start, 'end_time' => $end, 'duration_minutes' => 30, 'overtime_type' => 'dw',
                    'description' => 'Operasional', 'source' => 'rekomendasi_sistem', 'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        OvertimeEntry::query()->insert($rows);   // 620 baris

        $startedAt = microtime(true);
        $response = $this->get('/api/v1/attendance-imports/template?period=2026-10&type=lembur_dw')->assertOk();
        $this->assertLessThan(15, microtime(true) - $startedAt);

        // Rumus tidak dihitung di server; Excel menghitung ulang semuanya saat file dibuka.
        $zip = new \ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('fullCalcOnLoad="1"', $zip->getFromName('xl/workbook.xml'));
        $zip->close();

        $sheet = $this->spreadsheetOf($response)->getSheetByName('Lembur DW');
        $this->assertStringStartsWith('=IF(B5="","",IFERROR(VLOOKUP(B5&"",', $sheet->getCell('C5')->getValue());
        $this->assertSame('Ani', $sheet->getCell('C5')->getCalculatedValue());
        $this->assertSame('7761', $sheet->getCell('B624')->getValue());
    }

    public function test_overtime_templates_use_lookup_sheet_and_saved_entries(): void
    {
        $this->casual('Tanpa Reg');   // tidak bisa diisi di template lembur
        $this->storeOvertime($this->ani, '2026-10-03', '17:00', '19:00');

        $name = 'Template Lembur DW - Oktober 2026.xlsx';
        $this->get('/api/v1/attendance-imports/template?period=2026-10&type=lembur_dw')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$name.'"; filename*=UTF-8\'\''.rawurlencode($name));

        $spreadsheet = $this->template('lembur_reguler');
        $sheet = $spreadsheet->getSheetByName('Lembur Reguler');
        $data = $spreadsheet->getSheetByName('Data Karyawan');

        $this->assertSame(['Lembur Reguler', 'Petunjuk', 'Data Karyawan', 'Daftar'], $spreadsheet->getSheetNames());

        // Kolom identitas sama dengan template daftar hadir (terisi otomatis), lalu kolom lembur.
        $this->assertSame(
            ['No', 'No. Registrasi', 'Nama*', 'Jabatan*', 'Status*', 'Sub Unit*', 'Kode Afd*', 'Objek Penempatan*', 'Kelompok Upah*',
                'Cost Center*', 'Uraian Cost Center*', 'Tanggal', 'Jam Mulai', 'Jam Selesai', 'Durasi*', 'Uraian Pekerjaan'],
            array_map(fn (string $column) => $sheet->getCell("{$column}2")->getValue(), range('A', 'P')),
        );

        // Baris 4 = baris filter; data mulai baris 5; dibekukan sampai Jabatan.
        $this->assertSame(EmployeeExportWorkbook::FILTER_GREY, $sheet->getStyle('C4')->getFill()->getStartColor()->getRGB());
        $this->assertFilterRowAllowsFilterAndSort($sheet, 'C4');
        $this->assertSame(['No', 'No. Registrasi', 'Nama', 'Durasi'], [
            $sheet->getCell('A4')->getValue(), $sheet->getCell('B4')->getValue(), $sheet->getCell('C4')->getValue(), $sheet->getCell('O4')->getValue(),
        ]);
        $this->assertSame('A4:P205', $sheet->getAutoFilter()->getRange());
        // Boleh sisip, sembunyikan, & hapus baris; semua sel baris data (termasuk kolom abu-abu & di luar tabel)
        // tidak dikunci, karena Excel menolak urut & hapus baris yang berisi sel terkunci.
        $this->assertFalse($sheet->getProtection()->getInsertRows());
        $this->assertFalse($sheet->getProtection()->getDeleteRows());
        $this->assertFalse($sheet->getProtection()->getFormatRows());
        $this->assertSame(Protection::PROTECTION_UNPROTECTED, $sheet->getStyle('C5')->getProtection()->getLocked());
        $this->assertSame(Protection::PROTECTION_UNPROTECTED, $sheet->getParent()->getCellXfByIndex($sheet->getRowDimension(205)->getXfIndex())->getProtection()->getLocked());
        $this->assertSame('E5', $sheet->getFreezePane());

        $this->assertSame('7761', $sheet->getCell('B5')->getValue());
        $this->assertSame('03/10/2026', $sheet->getCell('L5')->getFormattedValue());
        $this->assertSame('17:00', $sheet->getCell('M5')->getFormattedValue());
        $this->assertStringContainsString("VLOOKUP(B5&\"\",'Data Karyawan'!\$A:\$J,2,FALSE)", $sheet->getCell('C5')->getValue());
        $this->assertStringContainsString("VLOOKUP(B5&\"\",'Data Karyawan'!\$A:\$J,10,FALSE)", $sheet->getCell('K5')->getValue());
        $this->assertStringContainsString('MOD(N5-M5,1)', $sheet->getCell('O5')->getValue());
        // Baris kosong cadangan tetap berumus, jadi identitas terisi otomatis begitu No. Registrasi diketik.
        $this->assertNull($sheet->getCell('B205')->getValue());
        $this->assertStringContainsString('VLOOKUP(B205&', $sheet->getCell('C205')->getValue());
        $this->assertSame(['No. Registrasi', 'Nama', 'Jabatan', 'Status'], [$data->getCell('A1')->getValue(), $data->getCell('B1')->getValue(), $data->getCell('C1')->getValue(), $data->getCell('D1')->getValue()]);
        $this->assertSame(['7761', '7530'], [$data->getCell('A2')->getValue(), $data->getCell('A3')->getValue()]);
        $this->assertNull($data->getCell('A4')->getValue());   // karyawan tanpa No. Registrasi tidak ada
        $this->assertSame(DataValidation::TYPE_DATE, $sheet->getDataValidationCollection()['L5:L205']->getType());
    }

    // -------------------------------------------------------------------------
    // Pratinjau daftar hadir
    // -------------------------------------------------------------------------

    public function test_attendance_preview_reports_errors_warnings_and_conflicts(): void
    {
        $capped = $this->casual('Cici', '8001');
        EmployeeWageScheme::create(['employee_id' => $capped->id, 'wage_type' => 'Harian', 'base_salary' => 2_500_000, 'is_capped' => true, 'cap_days' => 20, 'effective_date' => '2026-01-01']);
        $left = $this->casual('Dodi', '8002');
        $this->terminate($left, '2026-10-15');
        $missing = $this->casual('Euis', '8003');
        $this->casual('Fajar', '8004');
        $this->terminate(Employee::where('name', 'Fajar')->sole(), '2026-09-01');   // tidak aktif sepanjang Oktober
        $permanent = Employee::factory()->active()->create(['name' => 'Tetap', 'registration_number' => '0101']);
        $this->storeCode($this->ani, '2026-10-05', 'K', AttendanceSource::Manual);

        $spreadsheet = $this->template('daftar_hadir');
        $sheet = $spreadsheet->getSheetByName('Daftar Hadir');
        $this->setCodes($spreadsheet, $this->ani, [1 => 'K', 2 => 'X', 5 => 'S']);
        $this->setCodes($spreadsheet, $capped, array_fill_keys(range(1, 21), 'K'));
        $this->setCodes($spreadsheet, $left, [14 => 'K', 16 => 'K']);
        // Euis dihapus dari file; baris kembar Ani; karyawan tidak dikenal, Tetap, dan Fajar ditambahkan manual.
        $sheet->removeRow($this->attendanceRow($sheet, $missing));
        $extra = [['', $this->ani->registration_number], ['', '9999'], ['', '0101'], ['', '8004']];
        foreach ($extra as $offset => [$id, $registration]) {
            $row = 20 + $offset;
            $sheet->setCellValue("A{$row}", $id);
            $sheet->setCellValueExplicit("C{$row}", $registration, DataType::TYPE_STRING);
            $sheet->setCellValue(AttendanceListLayout::dateLetter(1).$row, 'K');
        }

        $response = $this->preview($spreadsheet, 'daftar_hadir')->assertOk();
        $rows = collect($response->json('data.rows'));

        $ani = $rows->firstWhere('row', $this->attendanceRow($sheet, $this->ani));
        $this->assertSame('salah', $ani['status']);
        $this->assertSame(['Tgl 2' => 'Kode "X" tidak dikenal (pakai K, DW, I, S, M, C, atau L)'], $this->messages($ani));

        $dodi = $this->previewRow($response, $left);
        $this->assertSame(['Tgl 16' => 'Karyawan tidak aktif pada tanggal 16'], $this->messages($dodi));

        $this->assertSame('Baris kembar: karyawan ini sudah ada di baris '.$this->attendanceRow($sheet, $this->ani), $this->messages($rows->firstWhere('row', 20))['No. Registrasi']);
        $this->assertSame('No. Registrasi 9999 tidak ditemukan', $this->messages($rows->firstWhere('row', 21))['No. Registrasi']);
        $this->assertSame('Bukan karyawan Casual / Daily Worker', $this->messages($rows->firstWhere('row', 22))['No. Registrasi']);
        $this->assertSame('Karyawan tidak aktif dalam periode Oktober 2026', $this->messages($rows->firstWhere('row', 23))['No. Registrasi']);

        $cici = $this->previewRow($response, $capped);
        $this->assertSame('peringatan', $cici['status']);
        $this->assertSame(['K' => 'Jumlah K (21) melebihi batas 20 hari pada skema upah'], $this->messages($cici, 'warnings'));

        $euis = $this->previewRow($response, $missing);
        $this->assertNull($euis['row']);
        $this->assertStringContainsString('tidak ada di file', $this->messages($euis, 'warnings')['No. Registrasi']);

        $response->assertJsonPath('data.summary.invalid', 6)->assertJsonPath('data.summary.conflicts', 0);
        $this->assertNotNull($permanent);
    }

    public function test_attendance_preview_conflict_with_manual_or_mobile_data(): void
    {
        $this->storeCode($this->ani, '2026-10-05', 'K', AttendanceSource::Manual);
        $this->storeCode($this->ani, '2026-10-06', 'K', AttendanceSource::Mobile);
        $this->storeCode($this->ani, '2026-10-07', 'K', AttendanceSource::ImportExcel);

        $spreadsheet = $this->template('daftar_hadir');
        $this->setCodes($spreadsheet, $this->ani, [5 => 'S', 6 => null, 7 => 'I', 8 => 'K']);

        $response = $this->preview($spreadsheet, 'daftar_hadir')->assertOk();
        $ani = $this->previewRow($response, $this->ani);

        $this->assertSame('peringatan', $ani['status']);
        $this->assertSame([
            ['date' => '2026-10-05', 'current_code' => 'K', 'current_source' => 'manual', 'file_code' => 'S'],
            ['date' => '2026-10-06', 'current_code' => 'K', 'current_source' => 'mobile', 'file_code' => null],
        ], $ani['conflicts']);
        $response->assertJsonPath('data.summary', [
            'total_rows' => 2, 'invalid' => 0, 'warnings' => 0, 'conflicts' => 2,
            'will_create' => 1, 'will_update' => 1, 'will_delete' => 0, 'unchanged' => 0,
        ]);
    }

    public function test_rejects_file_of_another_period_or_type(): void
    {
        $this->preview($this->template('daftar_hadir', '2026-09'), 'daftar_hadir')
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'File ini untuk periode September 2026, bukan Oktober 2026. Unduh template periode Oktober 2026.');

        $this->preview($this->template('lembur_reguler'), 'lembur_dw')
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'File bukan template Lembur DW. Unduh template terbaru, isi, lalu unggah kembali tanpa mengubah nama sheet.');

        $this->getJson('/api/v1/attendance-imports?period=2026-12')->assertUnprocessable();
    }

    // -------------------------------------------------------------------------
    // Pratinjau lembur
    // -------------------------------------------------------------------------

    public function test_overtime_preview_errors_warnings_and_midnight(): void
    {
        $this->storeCode($this->ani, '2026-10-05', 'S');
        $this->storeCode($this->ani, '2026-10-06', 'K');
        $this->storeCode($this->ani, '2026-10-07', 'L');
        $this->storeCode($this->ani, '2026-10-12', 'K');
        $this->storeCode($this->ani, '2026-10-13', 'K');
        $this->storeOvertime($this->ani, '2026-10-06', '18:00', '20:00', 'dw');
        $this->storeOvertime($this->ani, '2026-10-13', '10:00', '12:00', 'reguler', 'manual');

        $spreadsheet = $this->template('lembur_reguler');
        $this->setOvertimeRows($spreadsheet, 'lembur_reguler', [
            ['7761', '05/10/2026', '17:00', '18:00', 'Sakit tapi lembur'],          // 5: berkode S -> salah
            ['7761', '06/10/2026', '19:00', '21:00', 'Tabrakan DW'],                // 6: tumpang tindih lembur DW tersimpan
            ['7761', '07/10/2026', '22:00', '02:00', 'Lewat tengah malam'],         // 7: benar, hari L -> peringatan
            ['7761', '07/10/2026', '23:00', '23:30', 'Tabrakan dalam file'],        // 8: tumpang tindih baris 7
            ['7761', '12/10/2026', '13:00', '18:00', 'Lebih dari 4 jam'],           // 9: > 4 jam/hari
            ['7761', '13/10/2026', '11:00', '12:00', 'Tabrakan manual'],            // 10: tumpang tindih lembur manual -> salah
            ['9999', '01/11/2026', '17.00', '17:00', null],                         // 11: semua salah
            ['7761', '14/10/2026', '07:00', '07:00', 'Durasi nol'],                 // 12: durasi 0
        ]);

        $response = $this->preview($spreadsheet, 'lembur_reguler')->assertOk();
        $rows = collect($response->json('data.rows'))->keyBy('row');

        $this->assertSame(['Tanggal' => 'Tanggal ini berkode S (Sakit) di daftar hadir'], $this->messages($rows[5]));
        $this->assertSame(['Jam Mulai' => 'Tumpang tindih dengan Lembur DW tersimpan (18:00–20:00)'], $this->messages($rows[6]));
        $this->assertSame('peringatan', $rows[7]['status']);
        $this->assertSame(['Tanggal' => 'Tanggal ini berkode L (Libur) di daftar hadir'], $this->messages($rows[7], 'warnings'));
        $this->assertSame(['Jam Mulai' => 'Tumpang tindih dengan baris 7 (22:00–02:00)'], $this->messages($rows[8]));
        $this->assertSame(['Durasi' => 'Lebih dari 4 jam lembur pada tanggal ini (5 jam)'], $this->messages($rows[9], 'warnings'));
        $this->assertSame(['Jam Mulai' => 'Tumpang tindih dengan Lembur Reguler manual tersimpan (10:00–12:00)'], $this->messages($rows[10]));
        $this->assertSame([], $rows[10]['conflicts']);
        $this->assertSame([
            'No. Registrasi' => 'No. Registrasi 9999 tidak ditemukan',
            'Tanggal' => 'Tanggal di luar periode Oktober 2026',
            'Durasi' => 'Durasi lembur 0 (jam mulai sama dengan jam selesai)',
            'Uraian Pekerjaan' => 'Uraian Pekerjaan wajib diisi',
        ], $this->messages($rows[11]));
        $this->assertSame(['Durasi' => 'Durasi lembur 0 (jam mulai sama dengan jam selesai)'], array_intersect_key($this->messages($rows[12]), ['Durasi' => 1]));

        $response->assertJsonPath('data.summary.will_create', 2)->assertJsonPath('data.summary.conflicts', 0);

        // Ada baris salah (termasuk tumpang tindih dengan lembur manual) -> seluruh unggahan ditolak, tidak ada yang berubah.
        $before = OvertimeEntry::count();
        $this->import($spreadsheet, 'lembur_reguler')->assertUnprocessable();
        $this->assertSame($before, OvertimeEntry::count());
        $this->assertSame(0, OvertimeEntry::where('overtime_type', 'reguler')->where('source', 'import_excel')->count());
    }

    public function test_overtime_weekly_limit_counts_both_types_and_blank_day_warning(): void
    {
        // Minggu 12–18 Oktober: DW tersimpan 15 jam + file reguler 4 jam = 19 jam > 18.
        foreach (['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'] as $date) {
            $this->storeCode($this->ani, $date, 'K');
            $this->storeOvertime($this->ani, $date, '17:00', '20:00', 'dw');
        }

        $spreadsheet = $this->template('lembur_reguler');
        $this->setOvertimeRows($spreadsheet, 'lembur_reguler', [
            ['7761', '17/10/2026', '08:00', '12:00', 'Akhir pekan'],   // tanggal tanpa kode
        ]);

        $row = collect($this->preview($spreadsheet, 'lembur_reguler')->assertOk()->json('data.rows'))->first();
        $this->assertSame('peringatan', $row['status']);
        $this->assertEqualsCanonicalizing([
            'Tanggal ini belum berkode di daftar hadir',
            'Lebih dari 18 jam lembur pada minggu ini (19 jam)',
        ], array_column($row['warnings'], 'message'));
    }

    // -------------------------------------------------------------------------
    // Simpan
    // -------------------------------------------------------------------------

    public function test_store_is_rejected_when_any_row_is_invalid(): void
    {
        $spreadsheet = $this->template('daftar_hadir');
        $this->setCodes($spreadsheet, $this->ani, [1 => 'K', 2 => 'X']);

        $row = $this->attendanceRow($spreadsheet->getSheetByName('Daftar Hadir'), $this->ani);
        $response = $this->import($spreadsheet, 'daftar_hadir')->assertUnprocessable();
        $this->assertSame(['Tgl 2: Kode "X" tidak dikenal (pakai K, DW, I, S, M, C, atau L)'], $response->json('errors')["rows.{$row}"]);
        $this->assertSame(0, AttendanceDay::count());
        $this->assertSame(0, AttendanceImportBatch::count());
    }

    public function test_reupload_replaces_import_data_keeps_manual_and_records_conflicts(): void
    {
        $first = $this->template('daftar_hadir');
        $this->setCodes($first, $this->ani, [1 => 'K', 2 => 'K', 3 => 'K']);
        $this->setCodes($first, $this->budi, [1 => 'K']);
        $this->import($first, 'daftar_hadir')
            ->assertCreated()
            ->assertJsonPath('data.summary', ['created' => 4, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0, 'conflicts' => 0]);

        // Admin mengoreksi tanggal 3 secara manual; unggahan kedua mengosongkan tanggal 2, mengubah tanggal 3,
        // dan Budi tidak ada di file.
        $this->storeCode($this->ani, '2026-10-03', 'I', AttendanceSource::Manual);
        $second = $this->template('daftar_hadir');
        $this->setCodes($second, $this->ani, [1 => 'K', 2 => null, 3 => 'K', 4 => 'S']);
        $second->getSheetByName('Daftar Hadir')->removeRow($this->attendanceRow($second->getSheetByName('Daftar Hadir'), $this->budi));

        $this->import($second, 'daftar_hadir')
            ->assertCreated()
            ->assertJsonPath('data.batch.status', 'aktif')
            ->assertJsonPath('data.summary', ['created' => 1, 'updated' => 0, 'deleted' => 2, 'unchanged' => 1, 'conflicts' => 1]);

        $codes = AttendanceDay::query()->orderBy('date')->get()->mapWithKeys(fn (AttendanceDay $day) => [$day->employee->name.' '.$day->date->day => $day->status_code->value.'/'.$day->source->value])->all();
        $this->assertSame(['Ani 1' => 'K/import_excel', 'Ani 3' => 'I/manual', 'Ani 4' => 'S/import_excel'], $codes);

        $this->assertSame(['diganti', 'aktif'], AttendanceImportBatch::orderBy('created_at')->orderBy('id')->get()->map(fn ($batch) => $batch->status->value)->all());
        $conflict = AttendanceImportConflict::sole();
        $this->assertSame(['I', 'manual', 'K'], [$conflict->current_value, $conflict->current_source->value, $conflict->file_value]);

        // Hari yang dikosongkan tetap punya jejak walaupun barisnya sudah dihapus.
        $cleared = AttendanceDayChange::where('action', 'Dihapus')->where('employee_id', $this->ani->id)->sole();
        $this->assertNull($cleared->attendance_day_id);
        $this->assertSame('2026-10-02', $cleared->date->toDateString());
        $this->assertSame(['status_code', 'K', null], [$cleared->items()->sole()->field, $cleared->items()->sole()->old_value, $cleared->items()->sole()->new_value]);
    }

    public function test_overtime_store_replaces_import_entries_of_that_type_only(): void
    {
        $this->storeCode($this->ani, '2026-10-06', 'K');
        $this->storeCode($this->ani, '2026-10-07', 'K');
        $this->storeOvertime($this->ani, '2026-10-06', '17:00', '18:00');                     // reguler impor lama -> diganti
        $this->storeOvertime($this->ani, '2026-10-06', '08:00', '09:00', 'reguler', 'manual');  // tetap
        $this->storeOvertime($this->ani, '2026-10-07', '17:00', '18:00', 'dw');                 // jenis lain -> tetap

        $spreadsheet = $this->template('lembur_reguler');
        $this->setOvertimeRows($spreadsheet, 'lembur_reguler', [
            ['7761', '06/10/2026', '17:00', '18:00', ''],   // baris lama dari template ditimpa
            ['7761', '06/10/2026', '22:00', '01:30', 'Lewat tengah malam'],
            ['7761', '07/10/2026', '19:00', '20:00', 'Pelayanan tamu'],
        ]);
        $sheet = $spreadsheet->getSheet(0);
        $sheet->setCellValue(OvertimeLayout::letter('description').OvertimeLayout::FIRST_DATA_ROW, 'Persiapan acara');

        $this->import($spreadsheet, 'lembur_reguler')
            ->assertCreated()
            ->assertJsonPath('data.summary.created', 3)
            ->assertJsonPath('data.summary.deleted', 1);

        $regular = OvertimeEntry::where('overtime_type', 'reguler')->orderBy('date')->orderBy('start_time')->get();
        $this->assertSame(['08:00 manual', '17:00 import_excel', '22:00 import_excel', '19:00 import_excel'],
            $regular->map(fn ($entry) => substr($entry->start_time, 0, 5).' '.$entry->source->value)->all());
        $this->assertSame(210, $regular->first(fn (OvertimeEntry $entry) => str_starts_with($entry->start_time, '22:00'))->duration_minutes);
        $this->assertSame(1, OvertimeEntry::where('overtime_type', 'dw')->count());
    }

    public function test_locked_period_rejects_upload_but_allows_reading(): void
    {
        PayrollPeriod::create(['period' => '2026-10', 'status' => PayrollPeriodStatus::Locked, 'locked_at' => now()]);
        $spreadsheet = $this->template('daftar_hadir');

        $this->import($spreadsheet, 'daftar_hadir')
            ->assertStatus(423)
            ->assertJsonPath('message', 'Periode upah Oktober 2026 sudah dikunci. Absensi & lembur bulan itu tidak bisa diubah.');
        $this->preview($spreadsheet, 'daftar_hadir')->assertStatus(423);

        $this->getJson('/api/v1/attendance-imports?period=2026-10')
            ->assertOk()
            ->assertJsonPath('data.period_status', 'dikunci')
            ->assertJsonCount(3, 'data.sections')
            ->assertJsonPath('data.sections.0.import_type', 'daftar_hadir')
            ->assertJsonPath('data.sections.0.active_batch', null);
    }

    /**
     * Baris filter tidak dikunci (Excel menolak filter & urut pada sheet terproteksi kalau baris judul filternya terkunci),
     * tetapi isiannya ditolak validasi data. Judul tetap dikunci & sheet tetap diproteksi dengan izin filter & urut.
     */
    private function assertFilterRowAllowsFilterAndSort(Worksheet $sheet, string $cell): void
    {
        $this->assertSame(Protection::PROTECTION_UNPROTECTED, $sheet->getStyle($cell)->getProtection()->getLocked());
        $this->assertSame(DataValidation::TYPE_CUSTOM, $sheet->getCell($cell)->getDataValidation()->getType());
        $this->assertSame('FALSE', $sheet->getCell($cell)->getDataValidation()->getFormula1());
        $this->assertNotSame(Protection::PROTECTION_UNPROTECTED, $sheet->getStyle('A1')->getProtection()->getLocked());
        $this->assertTrue($sheet->getProtection()->getSheet());
        $this->assertFalse($sheet->getProtection()->getAutoFilter());
        $this->assertFalse($sheet->getProtection()->getSort());
    }
}
