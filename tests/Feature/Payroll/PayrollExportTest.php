<?php

namespace Tests\Feature\Payroll;

use App\Http\Controllers\Api\V1\Payroll\PayrollExportController;
use App\Models\Employee;
use App\Models\EmployeeWageScheme;
use App\Models\OvertimeEntry;
use App\Models\PlacementObject;
use App\Models\PositionAssignment;
use App\Models\User;
use App\Services\Payroll\PayrollExportDocument;
use App\Support\DocumentStyle;
use Database\Seeders\SignatureSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

/**
 * Pengujian ekspor GET /payroll/export (Daftar Upah, Payroll, SPKL; Excel / PDF), dipisah per kategori karyawan.
 * Data uji di PayrollTestHelpers: Ani & Cici = Casual (Gunung Mas, Tanpa Kelompok), Budi = Daily Worker (Petugas Parkir).
 */
class PayrollExportTest extends TestCase
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
    // Daftar Upah
    // -------------------------------------------------------------------------

    public function test_daftar_upah_starts_with_rekap_then_one_sheet_per_group_of_the_category(): void
    {
        $response = $this->export('document=daftar_upah&employee_category=casual')->assertOk();
        $this->assertFileName($response, 'Daftar Upah Karyawan Casual - 2 Kelompok - Oktober 2026 (DRAF).xlsx');

        // Hanya karyawan Casual: Petugas Parkir (Budi, Daily Worker) tidak ada. Sheet PAYROLL tidak ikut.
        $spreadsheet = $this->workbook($response);
        $this->assertSame(['REKAP', 'Gunung Mas', 'Tanpa Kelompok'], $spreadsheet->getSheetNames());

        $rekap = $spreadsheet->getSheetByName('REKAP');
        $this->assertSame('REKAPITULASI '.PayrollExportDocument::TITLE, $rekap->getCell('A3')->getValue());
        $this->assertSame(['No', 'URAIAN', 'Jumlah Karyawan', 'Gaji', 'Lembur', 'Tunjangan', 'Uang Makan', 'Jumlah', 'Potongan', 'Diterima'], $rekap->rangeToArray('A6:J6')[0]);
        $this->assertSame([1, 'Gunung Mas', 1, 2_200_000, 52_600, 75_000, 220_000, 2_547_600, 0, 2_547_600], $rekap->rangeToArray('A7:J7', null, false, false)[0]);
        $this->assertSame([2, 'Tanpa Kelompok', 1, 0, 0, 0, 0, 0, 0, 0], $rekap->rangeToArray('A8:J8', null, false, false)[0]);
        $this->assertSame(['JUMLAH', null, 2, 2_200_000], $rekap->rangeToArray('A9:D9', null, false, false)[0]);
        $this->assertSame('DRAF', $rekap->getCell('J2')->getValue());

        // Baris 8 = judul objek penempatan (Ani tanpa objek -> LAINNYA), baris 9 = Ani, 10 = JUMLAH objek, 11 = JUMLAH TOTAL.
        $mountain = $spreadsheet->getSheetByName('Gunung Mas');
        $this->assertSame('PT. Perkebunan Nusantara I (Persero) Regional 2', $mountain->getCell('A1')->getValue());
        $this->assertSame(PayrollExportDocument::OTHER_OBJECT, $mountain->getCell('A8')->getValue());
        $this->assertSame(['Tahoma', 11.0], [$mountain->getStyle('C9')->getFont()->getName(), (float) $mountain->getStyle('C9')->getFont()->getSize()]);
        $this->assertSame([PageSetup::PAPERSIZE_FOLIO, PageSetup::ORIENTATION_LANDSCAPE, 1], [
            $mountain->getPageSetup()->getPaperSize(), $mountain->getPageSetup()->getOrientation(), $mountain->getPageSetup()->getFitToWidth(),
        ]);
        $this->assertGreaterThanOrEqual(mb_strlen('Operasional & Jabatan'), $mountain->getColumnDimension('M')->getWidth());
        // Kolom Nama tidak dominan: lebar tetap, nama panjang berlipat ke bawah.
        $this->assertSame([(float) DocumentStyle::NAME_COLUMN_WIDTH, true], [$mountain->getColumnDimension('C')->getWidth(), $mountain->getStyle('C9')->getAlignment()->getWrapText()]);
        // Isi rata tengah kecuali Nama (rata kiri, berjarak dari garis); "PPIP" & "Kebun" dipotong sebelum "/".
        $this->assertSame([Alignment::HORIZONTAL_LEFT, 1, Alignment::HORIZONTAL_CENTER, Alignment::HORIZONTAL_CENTER], [
            $mountain->getStyle('C9')->getAlignment()->getHorizontal(), $mountain->getStyle('C9')->getAlignment()->getIndent(),
            $mountain->getStyle('B9')->getAlignment()->getHorizontal(), $mountain->getStyle('K9')->getAlignment()->getHorizontal(),
        ]);
        $this->assertSame(["PPIP\n/PPh.Ps.21", "Kebun\n/Unit"], [$mountain->getCell('R7')->getValue(), $mountain->getCell('S7')->getValue()]);
        $this->assertSame(PayrollExportDocument::TITLE, $mountain->getCell('F1')->getValue());
        $this->assertSame(['Bulan : Oktober 2026', 'Hal. : 2'], [$mountain->getCell('S1')->getValue(), $mountain->getCell('S2')->getValue()]);
        $this->assertSame('Kelompok Daftar Upah : Gunung Mas', $mountain->getCell('A3')->getValue());
        $this->assertSame('Sub Unit : Kebun Gunung Mas (Kode Afd 01)', $mountain->getCell('A4')->getValue());
        $this->assertSame(['HARI KERJA', 'HKE', 'Absen'], [$mountain->getCell('G6')->getValue(), $mountain->getCell('G7')->getValue(), $mountain->getCell('H7')->getValue()]);
        $this->assertSame(['Ani', 2_200_000, 2_547_600], [$mountain->getCell('C9')->getValue(), $mountain->getCell('K9')->getValue(), $mountain->getCell('P9')->getValue()]);
        $this->assertSame([1, 'JUMLAH'], [$mountain->getCell('A10')->getValue(), $mountain->getCell('B10')->getValue()]);
        $this->assertSame(PayrollExportDocument::GRAND_TOTAL_LABEL, $mountain->getCell('A11')->getValue());
        $this->assertSame('Gunung Mas, 05 November 2026', $mountain->getCell('R13')->getValue());
        $this->assertSame('Disetujui', $this->firstValueInRow($mountain, 14));
        $this->assertSame('M. TAUFIK SIREGAR SE', $this->firstValueInRow($mountain, 23));   // ruang tanda tangan 8 baris di bawah jabatan
        $this->assertSame('DRAF', $mountain->getCell('S3')->getValue());

        $groupNet = $this->totalOf($mountain, 'U') + $this->totalOf($spreadsheet->getSheetByName('Tanpa Kelompok'), 'U');
        $this->assertSame($groupNet, $this->totalOf($rekap, 'J'));
    }

    public function test_daily_worker_daftar_upah_has_only_daily_workers(): void
    {
        $response = $this->export('document=daftar_upah&employee_category=daily_worker')->assertOk();
        $this->assertFileName($response, 'Daftar Upah Karyawan Daily Worker - Petugas Parkir - Oktober 2026 (DRAF).xlsx');

        $spreadsheet = $this->workbook($response);
        $this->assertSame(['REKAP', 'Petugas Parkir'], $spreadsheet->getSheetNames());
        $this->assertSame('Budi', $spreadsheet->getSheetByName('Petugas Parkir')->getCell('C9')->getValue());
        $this->assertSame(1_500_000, $this->totalOf($spreadsheet->getSheetByName('REKAP'), 'J'));
    }

    // -------------------------------------------------------------------------
    // Payroll
    // -------------------------------------------------------------------------

    public function test_payroll_document_lists_accounts_per_group_and_counts_employees_without_account(): void
    {
        // Baris DU "ASRUL ANAM/Diana Andriani": kiri karyawan, kanan pemegang rekening.
        $this->ani->bankAccounts()->create([
            'bank_name' => 'BRI', 'account_number' => '367801009819535', 'account_holder' => 'DIANA ANDRIANI', 'effective_date' => '2026-10-01',
        ]);

        $response = $this->export('document=payroll&employee_category=casual')->assertOk();
        $this->assertFileName($response, 'Payroll Karyawan Casual - 2 Kelompok - Oktober 2026 (DRAF).xlsx');
        $spreadsheet = $this->workbook($response);
        $this->assertSame(['REKAP', 'Gunung Mas', 'Tanpa Kelompok'], $spreadsheet->getSheetNames());

        $rekap = $spreadsheet->getSheetByName('REKAP');
        $this->assertSame('REKAPITULASI PAYROLL '.PayrollExportDocument::TITLE, $rekap->getCell('A3')->getValue());
        $this->assertSame(['No', 'URAIAN', 'Jumlah Karyawan', 'Total Transfer', 'Tanpa Rekening'], $rekap->rangeToArray('A6:E6')[0]);
        $this->assertSame([[1, 'Gunung Mas', 1, 2_547_600, 0], [2, 'Tanpa Kelompok', 1, 0, 1]], $rekap->rangeToArray('A7:E8', null, false, false));
        $this->assertSame(['JUMLAH', null, 2, 2_547_600, 1], $rekap->rangeToArray('A9:E9', null, false, false)[0]);

        $mountain = $spreadsheet->getSheetByName('Gunung Mas');
        $this->assertSame('PAYROLL '.PayrollExportDocument::TITLE, $mountain->getCell('A3')->getValue());
        $this->assertSame('Kelompok Daftar Upah : Gunung Mas', $mountain->getCell('A5')->getValue());
        $this->assertSame(['No', 'Nama', 'No. Reg', 'Bank', 'No. Rekening', 'Atas Nama', 'Jumlah Diterima'], $mountain->rangeToArray('A6:G6')[0]);
        $this->assertSame(PayrollExportDocument::OTHER_OBJECT, $mountain->getCell('A7')->getValue());   // judul objek penempatan
        $this->assertSame([1, 'Ani', '7761', 'BRI', '367801009819535', 'DIANA ANDRIANI', 2_547_600], $mountain->rangeToArray('A8:G8', null, false, false)[0]);
        $this->assertSame([1, 'JUMLAH', 2_547_600], [$mountain->getCell('A9')->getValue(), $mountain->getCell('B9')->getValue(), $mountain->getCell('G9')->getValue()]);
        $this->assertSame(2_547_600, $this->totalOf($mountain, 'G'));
        $this->assertSame([true, Alignment::HORIZONTAL_LEFT], [$mountain->getStyle('F8')->getAlignment()->getWrapText(), $mountain->getStyle('F8')->getAlignment()->getHorizontal()]);
        $this->assertSame('Gunung Mas, 05 November 2026', $this->firstValueInRow($mountain, 12));   // tanda tangan kelompok
    }

    public function test_group_sheets_are_split_per_placement_object_with_subtotals_and_others_last(): void
    {
        // Ani di Tea Walk, Euis di Bosscha Space (3 K = 300.000), Dodi tanpa objek (2 K = 200.000) -> LAINNYA paling akhir.
        PositionAssignment::where('employee_id', $this->ani->id)->sole()->placementObjects()->attach(PlacementObject::factory()->create(['name' => 'Tea Walk']));
        $this->mountainEmployee('Euis', '8101', 3, 'Bosscha Space');
        $this->mountainEmployee('Dodi', '8102', 2, null);

        $mountain = $this->workbook($this->export("document=daftar_upah&employee_category=casual&group_keys={$this->mountain->id}")->assertOk())
            ->getSheetByName('Gunung Mas');
        $this->assertSame([
            8 => ['Bosscha Space', null, null], 9 => [1, '8101', 'Euis'], 10 => [1, 'JUMLAH', null],
            11 => ['Tea Walk', null, null], 12 => [1, '7761', 'Ani'], 13 => [1, 'JUMLAH', null],
            14 => [PayrollExportDocument::OTHER_OBJECT, null, null], 15 => [1, '8102', 'Dodi'], 16 => [1, 'JUMLAH', null],
            17 => [PayrollExportDocument::GRAND_TOTAL_LABEL, null, null],
        ], array_map('array_values', $mountain->rangeToArray('A8:C17', null, false, false, true)));
        $this->assertSame([300_000, 2_547_600, 200_000, 3_047_600], [
            $mountain->getCell('U10')->getValue(), $mountain->getCell('U13')->getValue(), $mountain->getCell('U16')->getValue(), $mountain->getCell('U17')->getValue(),
        ]);
        // Judul objek tebal & bergaris bawah; JUMLAH objek tebal berlatar abu-abu.
        $this->assertSame([true, Font::UNDERLINE_SINGLE], [$mountain->getStyle('A8')->getFont()->getBold(), $mountain->getStyle('A8')->getFont()->getUnderline()]);
        $this->assertSame([true, 'BFBFBF'], [$mountain->getStyle('U10')->getFont()->getBold(), $mountain->getStyle('U10')->getFill()->getStartColor()->getRGB()]);

        // Payroll ikut dipecah per objek.
        $payroll = $this->workbook($this->export("document=payroll&employee_category=casual&group_keys={$this->mountain->id}")->assertOk())
            ->getSheetByName('Gunung Mas');
        $this->assertSame(['Bosscha Space', 'Euis', 'Tea Walk', 'Ani', PayrollExportDocument::OTHER_OBJECT, 'Dodi', PayrollExportDocument::GRAND_TOTAL_LABEL],
            [$payroll->getCell('A7')->getValue(), $payroll->getCell('B8')->getValue(), $payroll->getCell('A10')->getValue(), $payroll->getCell('B11')->getValue(),
                $payroll->getCell('A13')->getValue(), $payroll->getCell('B14')->getValue(), $payroll->getCell('A16')->getValue()]);
        $this->assertSame([300_000, 3_047_600], [$payroll->getCell('G9')->getValue(), $payroll->getCell('G16')->getValue()]);

        $pdf = $this->export("document=daftar_upah&employee_category=casual&format=pdf&group_keys={$this->mountain->id}")->assertOk();
        $this->assertStringStartsWith('%PDF', $this->content($pdf));
    }

    // -------------------------------------------------------------------------
    // Pilihan kelompok, kategori & validasi
    // -------------------------------------------------------------------------

    public function test_group_keys_select_groups_and_empty_or_unknown_choices_are_rejected(): void
    {
        $spreadsheet = $this->workbook($this->export("document=daftar_upah&employee_category=casual&group_keys={$this->mountain->id}")->assertOk());
        $this->assertSame(['REKAP', 'Gunung Mas'], $spreadsheet->getSheetNames());

        // Kelompok tanpa karyawan kategori itu dilewati; kalau semua kosong -> 422.
        $both = $this->export("document=daftar_upah&employee_category=daily_worker&group_keys={$this->mountain->id},{$this->parking->id}")->assertOk();
        $this->assertFileName($both, 'Daftar Upah Karyawan Daily Worker - Petugas Parkir - Oktober 2026 (DRAF).xlsx');
        $this->export("document=daftar_upah&employee_category=daily_worker&group_keys={$this->mountain->id}", true)->assertUnprocessable()
            ->assertJsonPath('errors.document.0', 'Tidak ada karyawan Daily Worker untuk Daftar Upah di kelompok yang dipilih');

        $this->export('document=daftar_upah&employee_category=casual&group_keys=tidak-ada', true)->assertUnprocessable()
            ->assertJsonPath('errors.group_keys.0', 'Kelompok tidak ditemukan di daftar upah periode ini: tidak-ada');

        $byDivision = $this->workbook($this->export('document=daftar_upah&employee_category=casual&group_by=division')->assertOk());
        $this->assertSame(['REKAP', 'Bidang Tanaman', 'Tanpa Bidang'], $byDivision->getSheetNames());
    }

    public function test_required_category_and_document_and_dw_only_for_casual(): void
    {
        $this->export('document=daftar_upah', true)->assertUnprocessable()
            ->assertJsonPath('errors.employee_category.0', 'Kategori karyawan harus Casual atau Daily Worker');
        $this->export('document=daftar_upah&employee_category=pkwt', true)->assertUnprocessable()
            ->assertJsonPath('errors.employee_category.0', 'Kategori karyawan harus Casual atau Daily Worker');
        $this->export('employee_category=casual', true)->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->export('document=spkl_lainnya&employee_category=casual', true)->assertUnprocessable()->assertJsonValidationErrors('document');

        foreach (['spkl_all', 'spkl_dw'] as $document) {
            $this->export("document={$document}&employee_category=daily_worker", true)->assertUnprocessable()
                ->assertJsonPath('errors.document.0', 'Lembur DW hanya untuk karyawan Casual');
        }
        $this->export("document=payroll&employee_category=casual&employee_ids={$this->ani->id}", true)->assertUnprocessable()
            ->assertJsonValidationErrors('employee_ids');

        // Endpoint SPKL lama sudah dihapus.
        $this->getJson('/api/v1/payroll/spkl?period=2026-10&type=reguler')->assertNotFound();
    }

    public function test_locked_export_has_no_draft_mark_and_pdf_is_rendered(): void
    {
        $this->postJson('/api/v1/payroll/lock', ['period' => '2026-10'])->assertOk();

        $response = $this->export('document=daftar_upah&employee_category=casual')->assertOk();
        $this->assertFileName($response, 'Daftar Upah Karyawan Casual - 2 Kelompok - Oktober 2026.xlsx');
        $this->assertNull($this->workbook($response)->getSheetByName('Gunung Mas')->getCell('S3')->getValue());

        $pdf = $this->export('document=payroll&employee_category=casual&format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $this->content($pdf));
        $this->assertFileName($pdf, 'Payroll Karyawan Casual - 2 Kelompok - Oktober 2026.pdf');
    }

    public function test_custom_sign_date_is_printed_on_rekap_and_every_group(): void
    {
        $spreadsheet = $this->workbook($this->export('document=daftar_upah&employee_category=casual&sign_date=2026-10-31')->assertOk());

        $this->assertSame(['Gunung Mas, 31 Oktober 2026'], $this->placeDates($spreadsheet->getSheetByName('REKAP')));
        $this->assertSame(['Gunung Mas, 31 Oktober 2026'], $this->placeDates($spreadsheet->getSheetByName('Gunung Mas')));
        $this->assertSame(['Gunung Mas, 31 Oktober 2026'], $this->placeDates($spreadsheet->getSheetByName('Tanpa Kelompok')));

        // SPKL & tanggal tanda tangan tidak mengubah DRAF.
        $spkl = $this->export('document=spkl_reguler&employee_category=casual&sign_date=2026-11-02')->assertOk();
        $this->assertStringContainsString('(DRAF)', $spkl->headers->get('Content-Disposition'));
        foreach ($this->workbook($spkl)->getAllSheets() as $sheet) {
            foreach ($this->placeDates($sheet) as $placeDate) {
                $this->assertStringEndsWith(', 02 November 2026', $placeDate, $sheet->getTitle());
            }
        }

        $this->export('document=payroll&employee_category=casual&format=pdf&sign_date=2026-10-31')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_show_draft_hides_draft_mark_on_draft_period_but_never_shows_it_on_locked_period(): void
    {
        // Periode draf, show_draft=0: tanpa DRAF di dokumen & nama file.
        $response = $this->export('document=daftar_upah&employee_category=casual&show_draft=0')->assertOk();
        $this->assertFileName($response, 'Daftar Upah Karyawan Casual - 2 Kelompok - Oktober 2026.xlsx');
        $spreadsheet = $this->workbook($response);
        $this->assertNull($spreadsheet->getSheetByName('REKAP')->getCell('J2')->getValue());
        $this->assertNull($spreadsheet->getSheetByName('Gunung Mas')->getCell('S3')->getValue());
        $this->assertFileName($this->export('document=spkl_reguler&employee_category=casual&format=pdf&show_draft=0')->assertOk(),
            'SPKL Lembur Reguler Karyawan Casual - Gunung Mas - Oktober 2026.pdf');

        // show_draft=1 pada periode draf = sama dengan bawaan.
        $this->assertFileName($this->export('document=daftar_upah&employee_category=casual&show_draft=1')->assertOk(),
            'Daftar Upah Karyawan Casual - 2 Kelompok - Oktober 2026 (DRAF).xlsx');

        // Periode dikunci: show_draft=1 tetap tanpa DRAF.
        $this->postJson('/api/v1/payroll/lock', ['period' => '2026-10'])->assertOk();
        $locked = $this->export('document=daftar_upah&employee_category=casual&show_draft=1')->assertOk();
        $this->assertFileName($locked, 'Daftar Upah Karyawan Casual - 2 Kelompok - Oktober 2026.xlsx');
        $this->assertNull($this->workbook($locked)->getSheetByName('Gunung Mas')->getCell('S3')->getValue());
    }

    public function test_invalid_or_too_early_sign_date_and_invalid_show_draft_are_rejected(): void
    {
        foreach (['31-10-2026', '2026-13-01', '2026-02-30', 'besok'] as $date) {
            $this->export("document=daftar_upah&employee_category=casual&sign_date={$date}", true)->assertUnprocessable()
                ->assertJsonPath('errors.sign_date.0', 'Tanggal tanda tangan tidak valid');
        }
        $this->export('document=daftar_upah&employee_category=casual&sign_date=2026-09-30', true)->assertUnprocessable()
            ->assertJsonPath('errors.sign_date.0', 'Tanggal tanda tangan tidak boleh sebelum 1 Oktober 2026');
        $this->export('document=daftar_upah&employee_category=casual&sign_date=2026-10-01')->assertOk();   // tanggal 1 periode boleh
        $this->export('document=daftar_upah&employee_category=casual&show_draft=ya', true)->assertUnprocessable()
            ->assertJsonPath('errors.show_draft.0', 'Tampilkan DRAF harus 1 atau 0');
    }

    public function test_pdf_has_rekap_page_then_one_page_per_group(): void
    {
        $response = $this->export('document=daftar_upah&employee_category=casual&format=pdf&group_by=division')->assertOk();

        $this->assertStringStartsWith('%PDF', $this->content($response));
        $this->assertFileName($response, 'Daftar Upah Karyawan Casual - 2 Kelompok - Oktober 2026 (DRAF).pdf');
        $this->assertSame(3, $this->pdfPageCount($response));   // REKAP, Bidang Tanaman, Tanpa Bidang
    }

    // -------------------------------------------------------------------------
    // SPKL
    // -------------------------------------------------------------------------

    public function test_spkl_reguler_excel_has_rekap_and_daily_rows_totals_and_paid_amount(): void
    {
        $response = $this->export('document=spkl_reguler&employee_category=casual')->assertOk();
        $this->assertFileName($response, 'SPKL Lembur Reguler Karyawan Casual - Gunung Mas - Oktober 2026 (DRAF).xlsx');

        $spreadsheet = $this->workbook($response);
        $this->assertSame(['REKAP', 'Gunung Mas'], $spreadsheet->getSheetNames());   // hanya karyawan yang punya lembur reguler
        $rekap = $spreadsheet->getSheetByName('REKAP');
        $this->assertSame('REKAPITULASI DAFTAR LEMBUR', $rekap->getCell('A3')->getValue());
        $this->assertSame(['No', 'URAIAN', 'Jumlah Karyawan', 'Total Jam', 'Jam Dibayar', 'Upah Lembur'], $rekap->rangeToArray('A6:F6')[0]);
        $this->assertEquals([1, 'Gunung Mas', 1, 2, 3.5, 52_600], $rekap->rangeToArray('A7:F7', null, false, false)[0]);
        $this->assertEquals(['JUMLAH', null, 1, 2, 3.5, 52_600], $rekap->rangeToArray('A8:F8', null, false, false)[0]);

        $sheet = $spreadsheet->getSheetByName('Gunung Mas');
        $this->assertSame('PTPN I (Persero) Regional 2', $sheet->getCell('A1')->getValue());   // disingkat supaya judul bisa tepat di tengah
        $this->assertSame(['Tahoma', 11.0], [$sheet->getStyle('A1')->getFont()->getName(), (float) $sheet->getStyle('A1')->getFont()->getSize()]);
        $this->assertSame('DAFTAR LEMBUR', $this->spklTitle($sheet, 1));
        // Judul tepat di tengah: total lebar kolom di kiri gabungan judul = di kanannya.
        $widths = array_map(fn (string $letter) => $sheet->getColumnDimension($letter)->getWidth(), range('A', 'N'));
        [[$titleFrom], [$titleTo]] = Coordinate::rangeBoundaries($this->spklTitleRange($sheet, 1));
        $this->assertEqualsWithDelta(array_sum(array_slice($widths, 0, $titleFrom - 1)), array_sum(array_slice($widths, $titleTo)), 0.01);
        $this->assertSame('BULAN : OKTOBER 2026 (DRAF)', $sheet->getCell('N1')->getValue());
        $this->assertSame(': Ani', $sheet->getCell('B4')->getValue());
        $this->assertSame(': Karyawan Casual', $sheet->getCell('B5')->getValue());
        $this->assertSame(': Pemandu Wisata', $sheet->getCell('B6')->getValue());
        $this->assertSame(': Rp 15.029', $sheet->getCell('M5')->getValue());

        // Judul Minggu/Libur 3 sub kolom; baris data mulai 10 (1 Okt = Kamis), tanggal angka saja; 5 Okt = baris 14.
        $this->assertSame(['7 Jam Pertama', 'Setelah 8 Jam Pertama', 'Setelah 9 Jam Kedua'], $sheet->rangeToArray('H9:J9', null, false, false)[0]);
        $this->assertSame(['Kamis', 1], [$sheet->getCell('A10')->getValue(), $sheet->getCell('B10')->getValue()]);
        $this->assertSame(['Senin', '5', '17:00', '19:00', '2', '1', '1', '-', '-', '-', 'Persiapan acara'], $sheet->rangeToArray('A14:K14', null, true, true)[0]);
        $this->assertNull($sheet->getCell('O9')->getValue());   // kolom Jenis hanya untuk SPKL Lembur Reguler + DW

        // Label ringkasan di A–D, ":" di kolom E (rata tengah, sejajar); jam dibayar dalam kolom bergaris.
        $this->assertSame(['Jumlah', 'Pengali', 'Total Jam', 'Jumlah Jam yang Dibayar'], array_map(fn (int $row) => $sheet->getCell("A{$row}")->getValue(), [41, 42, 43, 44]));
        $this->assertSame([':', ':', ':', ':'], array_map(fn (int $row) => $sheet->getCell("E{$row}")->getValue(), [41, 42, 43, 44]));
        $this->assertSame(Alignment::HORIZONTAL_CENTER, $sheet->getStyle('E41')->getAlignment()->getHorizontal());
        $this->assertSame(['1', '1', '-', '-', '-'], $sheet->rangeToArray('F41:J41', null, true, true)[0]);
        $this->assertSame(['Pengali', '1.5', '2', '2', '3', '4'], [$sheet->getCell('A42')->getValue(), ...$sheet->rangeToArray('F42:J42', null, true, true)[0]]);
        $this->assertSame(['Total Jam', '1.5', '2', '-', '-', '-'], [$sheet->getCell('A43')->getValue(), ...$sheet->rangeToArray('F43:J43', null, true, true)[0]]);
        $this->assertSame(['3,5 x Rp. 15.029', '= Rp. 52.600'], [$sheet->getCell('F44')->getValue(), $sheet->getCell('I44')->getValue()]);
        $this->assertSame(['A44:D44', 'F44:H44', 'I44:J44'], array_values(array_filter($sheet->getMergeCells(), fn (string $range) => str_ends_with($range, '44') && ! str_starts_with($range, 'K'))));
        $this->assertSame(Border::BORDER_THIN, $sheet->getStyle('F44')->getBorders()->getBottom()->getBorderStyle());
        $this->assertSame('Gunung Mas, 05 November 2026', $sheet->getCell('K45')->getValue());

        // Cetak A4 tegak dengan skala tetap (bukan "Fit To", yang membuat Excel mengabaikan pemisah halaman manual);
        // judul sub-kolom jam berlipat dengan huruf kecil supaya kolom ramping. Satu karyawan per halaman: lihat test di bawah.
        $setup = $sheet->getPageSetup();
        $this->assertSame([PageSetup::PAPERSIZE_A4, PageSetup::ORIENTATION_PORTRAIT, false], [$setup->getPaperSize(), $setup->getOrientation(), $setup->getFitToPage()]);
        $this->assertGreaterThanOrEqual(70, $setup->getScale());
        $this->assertSame([true, 9.0], [$sheet->getStyle('I9')->getAlignment()->getWrapText(), (float) $sheet->getStyle('I9')->getFont()->getSize()]);
        $this->assertSame(['Gaji Pokok 1 (satu) bulan', 'Tarif per jam'], [$sheet->getCell('I4')->getValue(), $sheet->getCell('I5')->getValue()]);

        $this->export('document=spkl_dw&employee_category=casual', true)->assertUnprocessable()
            ->assertJsonPath('errors.document.0', 'Tidak ada karyawan Casual untuk SPKL Lembur DW di kelompok yang dipilih');
    }

    public function test_spkl_excel_prints_one_employee_per_a4_portrait_page(): void
    {
        // Dua karyawan di kelompok Gunung Mas; Euis punya uraian panjang (berlipat) & dua lembur pada satu tanggal.
        $euis = $this->mountainEmployee('Euis', '8101', 2, null);
        $this->storeOvertime($euis, '2026-10-01', '17:00', '18:00');
        $this->storeOvertime($euis, '2026-10-01', '20:00', '21:00');
        OvertimeEntry::where('employee_id', $euis->id)->update(['description' => str_repeat('Membantu pekerjaan operasional di luar jam kerja ', 2)]);

        $sheet = $this->workbook($this->export('document=spkl_reguler&employee_category=casual')->assertOk())->getSheetByName('Gunung Mas');
        $setup = $sheet->getPageSetup();

        // Pemisah halaman tepat di akhir blok karyawan pertama (Ani), blok kedua mulai sesudahnya.
        $breaks = array_map(fn (string $cell) => (int) substr($cell, 1), array_keys($sheet->getRowBreaks()));
        $this->assertCount(1, $breaks);
        $this->assertSame('DAFTAR LEMBUR', $this->spklTitle($sheet, $breaks[0] + 2));
        $this->assertSame('A1:N'.$sheet->getHighestRow(), $setup->getPrintArea());

        // Setiap halaman (tinggi baris × skala) muat di A4 tegak bermargin 0,4 inci; lebar juga (± 6 pt per satuan kolom).
        $printable = [595.28 - 2 * 0.4 * 72, 841.89 - 2 * 0.4 * 72];
        $width = array_sum(array_map(fn (string $letter) => $sheet->getColumnDimension($letter)->getWidth(), range('A', 'N'))) * 6;
        $this->assertLessThanOrEqual($printable[0], $width * $setup->getScale() / 100);
        foreach ([[1, $breaks[0]], [$breaks[0] + 1, $sheet->getHighestRow()]] as [$from, $to]) {
            $height = array_sum(array_map(fn (int $row) => $sheet->getRowDimension($row)->getRowHeight(), range($from, $to)));
            $this->assertLessThanOrEqual($printable[1], $height * $setup->getScale() / 100, "baris {$from}-{$to}");
        }

        // Baris berlipat diberi tinggi sesuai jumlah barisnya (sel gabungan Uraian tidak menyesuaikan sendiri di Excel).
        $euisRow = $breaks[0] + 2 + 9;   // 1 Okt di blok Euis
        $this->assertSame(['17:00, 20:00', 'Membantu'], [$sheet->getCell("C{$euisRow}")->getValue(), strtok((string) $sheet->getCell("K{$euisRow}")->getValue(), ' ')]);
        $this->assertGreaterThanOrEqual(45.0, $sheet->getRowDimension($euisRow)->getRowHeight());
        $this->assertSame(15.0, $sheet->getRowDimension($euisRow + 1)->getRowHeight());   // tanggal tanpa lembur: satu baris
    }

    public function test_spkl_reguler_for_daily_worker(): void
    {
        $this->storeOvertime($this->budi, '2026-10-06', '17:00', '18:30');

        $response = $this->export('document=spkl_reguler&employee_category=daily_worker')->assertOk();

        $this->assertFileName($response, 'SPKL Lembur Reguler Karyawan Daily Worker - Petugas Parkir - Oktober 2026 (DRAF).xlsx');
        $sheet = $this->workbook($response)->getSheetByName('Petugas Parkir');
        $this->assertSame([': Budi', ': Karyawan Daily Worker'], [$sheet->getCell('B4')->getValue(), $sheet->getCell('B5')->getValue()]);
    }

    public function test_spkl_all_puts_regular_and_dw_in_one_block_with_type_column_in_excel(): void
    {
        // Ani: Reguler 05/10 17:00–19:00 (skenario) + DW 05/10 20:00–21:00 & 06/10 17:00–18:00.
        $this->storeOvertime($this->ani, '2026-10-05', '20:00', '21:00', 'dw');
        $this->storeOvertime($this->ani, '2026-10-06', '17:00', '18:00', 'dw');
        $payroll = $this->payrollRows()['Ani'];
        $expectedPay = $payroll['regular_overtime_pay'] + $payroll['dw_overtime_pay'];

        $response = $this->export('document=spkl_all&employee_category=casual')->assertOk();
        $this->assertFileName($response, 'SPKL Lembur Reguler + DW Karyawan Casual - Gunung Mas - Oktober 2026 (DRAF).xlsx');
        $spreadsheet = $this->workbook($response);

        // REKAP Excel: kelompok dipecah Reguler & DW; JUMLAH karyawan dihitung unik, jam & upah dijumlah.
        $rekap = $spreadsheet->getSheetByName('REKAP');
        $this->assertSame('REKAPITULASI DAFTAR LEMBUR', $rekap->getCell('A3')->getValue());
        $this->assertSame(['No', 'URAIAN', 'Jenis', 'Jumlah Karyawan', 'Total Jam', 'Jam Dibayar', 'Upah Lembur'], $rekap->rangeToArray('A6:G6')[0]);
        $this->assertEquals([
            [1, 'Gunung Mas', 'Reguler', 1, 2, 3.5, $payroll['regular_overtime_pay']],
            [2, 'Gunung Mas', 'DW', 1, 2, 3, $payroll['dw_overtime_pay']],
            ['JUMLAH', null, null, 1, 4, 6.5, $expectedPay],
        ], $rekap->rangeToArray('A7:G9', null, false, false));

        // Satu blok: 5 Okt dua baris (Reguler lalu DW), 6 Okt baris DW; kolom Jenis di O; upah = reguler + DW.
        $sheet = $spreadsheet->getSheetByName('Gunung Mas');
        $this->assertSame('DAFTAR LEMBUR', $this->spklTitle($sheet, 1));   // judul tanpa jenis lembur
        $this->assertSame('Jenis', $sheet->getCell('O8')->getValue());
        $this->assertSame(['Senin', '5', '17:00', '19:00', '2', '1', '1'], array_slice($sheet->rangeToArray('A14:G14', null, true, true)[0], 0, 7));
        $this->assertSame('Reguler', $sheet->getCell('O14')->getValue());
        $this->assertSame(['Senin', '5', '20:00', '21:00', '1', '1', '-'], $sheet->rangeToArray('A15:G15', null, true, true)[0]);
        $this->assertSame('DW', $sheet->getCell('O15')->getValue());
        $this->assertSame(['Selasa', '6', '17:00', '18:00'], $sheet->rangeToArray('A16:D16', null, true, true)[0]);
        $this->assertSame('DW', $sheet->getCell('O16')->getValue());
        // 31 tanggal + 1 baris tambahan (5 Okt Reguler & DW): Jumlah di baris 42, Jam yang Dibayar di baris 45.
        $this->assertSame(['Jumlah', '3', '1'], [$sheet->getCell('A42')->getValue(), ...$sheet->rangeToArray('F42:G42', null, true, true)[0]]);
        $this->assertSame('= Rp. '.number_format($expectedPay, 0, ',', '.'), $sheet->getCell('I45')->getValue());
        $this->assertSame('6,5 x Rp. 15.029', $sheet->getCell('F45')->getValue());

        // PDF: REKAP satu baris per kelompok (tanpa Jenis) + satu halaman per karyawan.
        $pdf = $this->export('document=spkl_all&employee_category=casual&format=pdf')->assertOk();
        $this->assertSame(2, $this->pdfPageCount($pdf));
    }

    public function test_spkl_pdf_for_selected_employees(): void
    {
        $response = $this->export("document=spkl_reguler&employee_category=casual&format=pdf&employee_ids={$this->ani->id},{$this->cici->id}")->assertOk();

        $this->assertStringStartsWith('%PDF', $this->content($response));
        $this->assertFileName($response, 'SPKL Lembur Reguler Karyawan Casual - 2 Kelompok - Oktober 2026 (DRAF).pdf');
        $this->assertSame(3, $this->pdfPageCount($response));   // REKAP + Ani + Cici (dipilih walau tanpa lembur)
    }

    public function test_large_spkl_pdf_is_rendered_in_parts_and_merged_into_one_file(): void
    {
        // Lebih dari satu bagian render: 25 karyawan baru + Ani = 26 karyawan = REKAP + 26 halaman dalam SATU file.
        $ids = collect(range(1, PayrollExportController::SPKL_PDF_PAGES_PER_PART))->map(fn (int $number) => $this->casual("Karyawan {$number}")->id);
        $ids = [...$ids, $this->ani->id];

        $response = $this->export('document=spkl_reguler&employee_category=casual&format=pdf&employee_ids='.implode(',', $ids))->assertOk();

        $this->assertSame(27, $this->pdfPageCount($response));

        // Render bersamaan (3 proses "php artisan hr:render-pdf"): 26 karyawan dibagi rata 9 + 9 + 8, hasil tetap satu file.
        config(['hr.pdf_processes' => 3]);
        $parallel = $this->export('document=spkl_reguler&employee_category=casual&format=pdf&employee_ids='.implode(',', $ids))->assertOk();
        $this->assertSame(27, $this->pdfPageCount($parallel));
    }

    // -------------------------------------------------------------------------
    // Bantuan
    // -------------------------------------------------------------------------

    /** GET /payroll/export periode Oktober 2026; $json = minta respons JSON (untuk 422). */
    private function export(string $query, bool $json = false): TestResponse
    {
        $url = "/api/v1/payroll/export?period=2026-10&{$query}";

        return $json ? $this->getJson($url) : $this->get($url);
    }

    private function assertFileName(TestResponse $response, string $fileName): void
    {
        $this->assertStringContainsString("filename*=UTF-8''".rawurlencode($fileName), $response->headers->get('Content-Disposition'));
    }

    private function pdfPageCount(TestResponse $response): int
    {
        return (new Fpdi)->setSourceFile($response->baseResponse->getFile()->getPathname());
    }

    private function content(TestResponse $response): string
    {
        return file_get_contents($response->baseResponse->getFile()->getPathname());
    }

    private function workbook(TestResponse $response): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($path, $this->content($response));

        return IOFactory::load($path);
    }

    /** Karyawan Casual kelompok "Gunung Mas" (Rp/hari 100.000) dengan $days hari K, di objek $objectName (null = tanpa objek). */
    private function mountainEmployee(string $name, string $registration, int $days, ?string $objectName): Employee
    {
        $employee = $this->casual($name, $registration);
        $assignment = PositionAssignment::factory()->create(['employee_id' => $employee->id]);
        if ($objectName !== null) {
            $assignment->placementObjects()->attach(PlacementObject::factory()->create(['name' => $objectName]));
        }
        EmployeeWageScheme::create([
            'employee_id' => $employee->id, 'wage_type' => 'Harian', 'base_salary' => 2_600_000, 'working_days' => 26,
            'payroll_group_id' => $this->mountain->id, 'effective_date' => '2026-01-01',
        ]);
        for ($day = 1; $day <= $days; $day++) {
            $this->storeCode($employee, sprintf('2026-10-%02d', $day), 'K');
        }

        return $employee;
    }

    /** Nilai pada baris JUMLAH (REKAP) / JUMLAH TOTAL (sheet kelompok) di kolom itu. */
    private function totalOf(Worksheet $sheet, string $column): int
    {
        for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
            if (in_array($sheet->getCell("A{$row}")->getValue(), ['JUMLAH', PayrollExportDocument::GRAND_TOTAL_LABEL], true)) {
                return (int) $sheet->getCell("{$column}{$row}")->getValue();
            }
        }
        $this->fail("Tidak ada baris JUMLAH di sheet {$sheet->getTitle()}");
    }

    /** Gabungan sel judul "DAFTAR LEMBUR" pada baris kop blok SPKL (posisinya ditengahkan menurut lebar kolom). */
    private function spklTitleRange(Worksheet $sheet, int $row): string
    {
        return collect($sheet->getMergeCells())->first(fn (string $range) => preg_match('/^[B-M]'.$row.':[B-M]'.$row.'$/', $range) === 1)
            ?? $this->fail("Tidak ada gabungan sel judul di baris {$row}");
    }

    private function spklTitle(Worksheet $sheet, int $row): mixed
    {
        return $sheet->getCell(strtok($this->spklTitleRange($sheet, $row), ':'))->getValue();
    }

    /** @return list<string> isi sel "<tempat>, <tanggal>" (blok tanda tangan) di sheet itu */
    private function placeDates(Worksheet $sheet): array
    {
        $found = [];
        foreach ($sheet->toArray(null, false, false) as $row) {
            foreach ($row as $value) {
                if (is_string($value) && preg_match('/^.+, \d{2} \p{L}+ \d{4}$/u', $value)) {
                    $found[] = $value;
                }
            }
        }

        return $found;
    }

    private function firstValueInRow(Worksheet $sheet, int $row): mixed
    {
        foreach ($sheet->rangeToArray("A{$row}:X{$row}", null, false, false)[0] as $value) {
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
