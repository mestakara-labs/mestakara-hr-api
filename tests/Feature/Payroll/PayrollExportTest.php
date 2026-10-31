<?php

namespace Tests\Feature\Payroll;

use App\Http\Controllers\Api\V1\Payroll\PayrollExportController;
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
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

/**
 * Pengujian ekspor Daftar Upah & SPKL (Excel / PDF). Data uji di PayrollTestHelpers.
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

    public function test_exports_all_groups_with_rekap_and_payroll_sheets_whose_totals_match(): void
    {
        $response = $this->get('/api/v1/payroll/export?period=2026-10')->assertOk();
        $this->assertStringContainsString(
            rawurlencode('Daftar Upah Karyawan Casual & DW - Kelompok Daftar Upah - Oktober 2026 (DRAF).xlsx'),
            $response->headers->get('Content-Disposition'),
        );

        $spreadsheet = $this->workbook($response);
        $this->assertSame(['Gunung Mas', 'Petugas Parkir', 'Tanpa Kelompok', 'REKAP', 'PAYROLL'], $spreadsheet->getSheetNames());

        $mountain = $spreadsheet->getSheetByName('Gunung Mas');
        $this->assertSame('PT. Perkebunan Nusantara I (Persero) Regional 2', $mountain->getCell('A1')->getValue());
        $this->assertSame(['Tahoma', 11.0], [$mountain->getStyle('C8')->getFont()->getName(), (float) $mountain->getStyle('C8')->getFont()->getSize()]);
        $this->assertSame([PageSetup::PAPERSIZE_FOLIO, PageSetup::ORIENTATION_LANDSCAPE, 1], [
            $mountain->getPageSetup()->getPaperSize(), $mountain->getPageSetup()->getOrientation(), $mountain->getPageSetup()->getFitToWidth(),
        ]);
        $this->assertGreaterThanOrEqual(mb_strlen('Operasional & Jabatan'), $mountain->getColumnDimension('M')->getWidth());
        // Kolom Nama tidak dominan: lebar tetap, nama panjang berlipat ke bawah.
        $this->assertSame([(float) DocumentStyle::NAME_COLUMN_WIDTH, true], [$mountain->getColumnDimension('C')->getWidth(), $mountain->getStyle('C8')->getAlignment()->getWrapText()]);
        // Isi rata tengah kecuali Nama (rata kiri, berjarak dari garis); "PPIP" & "Kebun" dipotong sebelum "/".
        $this->assertSame([Alignment::HORIZONTAL_LEFT, 1, Alignment::HORIZONTAL_CENTER, Alignment::HORIZONTAL_CENTER], [
            $mountain->getStyle('C8')->getAlignment()->getHorizontal(), $mountain->getStyle('C8')->getAlignment()->getIndent(),
            $mountain->getStyle('B8')->getAlignment()->getHorizontal(), $mountain->getStyle('K8')->getAlignment()->getHorizontal(),
        ]);
        $this->assertSame(['PPIP
/PPh.Ps.21', 'Kebun
/Unit'], [$mountain->getCell('R7')->getValue(), $mountain->getCell('S7')->getValue()]);
        $this->assertSame(PayrollExportDocument::TITLE, $mountain->getCell('F1')->getValue());
        $this->assertSame('Bulan : Oktober 2026', $mountain->getCell('S1')->getValue());
        $this->assertSame('Kelompok Daftar Upah : Gunung Mas', $mountain->getCell('A3')->getValue());
        $this->assertSame('Sub Unit : Kebun Gunung Mas (Kode Afd 01)', $mountain->getCell('A4')->getValue());
        $this->assertSame(['HARI KERJA', 'HKE', 'Absen'], [$mountain->getCell('G6')->getValue(), $mountain->getCell('G7')->getValue(), $mountain->getCell('H7')->getValue()]);
        $this->assertSame(['Ani', 2_200_000, 2_547_600], [$mountain->getCell('C8')->getValue(), $mountain->getCell('K8')->getValue(), $mountain->getCell('P8')->getValue()]);
        $this->assertSame('JUMLAH', $mountain->getCell('A9')->getValue());
        $this->assertSame('Gunung Mas, 05 November 2026', $mountain->getCell('R11')->getValue());
        $this->assertSame('Disetujui', $this->firstValueInRow($mountain, 12));
        $this->assertSame('M. TAUFIK SIREGAR SE', $this->firstValueInRow($mountain, 21));   // ruang tanda tangan 8 baris di bawah jabatan

        $groupNet = 0;
        foreach (['Gunung Mas', 'Petugas Parkir', 'Tanpa Kelompok'] as $title) {
            $groupNet += $this->totalOf($spreadsheet->getSheetByName($title), 'U');
        }
        $rekapNet = $this->totalOf($spreadsheet->getSheetByName('REKAP'), 'R');
        $payrollNet = $this->totalOf($spreadsheet->getSheetByName('PAYROLL'), 'J');
        $this->assertSame(4_047_600, $groupNet);
        $this->assertSame($groupNet, $rekapNet);
        $this->assertSame($groupNet, $payrollNet);
        $this->assertSame(['No', 'Reg', 'Nama', 'No Rek', 'Bank', 'Atas Nama', 'Gaji', 'Tunjangan', 'Potongan', 'Jumlah', 'Cost Center'],
            $spreadsheet->getSheetByName('PAYROLL')->rangeToArray('A6:K6')[0]);
        $this->assertSame('DRAF', $mountain->getCell('S3')->getValue());
    }

    public function test_exports_one_group_and_by_division_and_rejects_unknown_group(): void
    {
        $response = $this->get("/api/v1/payroll/export?period=2026-10&group_key={$this->mountain->id}")->assertOk();
        $this->assertStringContainsString(rawurlencode('Kelompok Daftar Upah - Gunung Mas - Oktober 2026 (DRAF).xlsx'), $response->headers->get('Content-Disposition'));
        $spreadsheet = $this->workbook($response);
        $this->assertSame(['Gunung Mas', 'PAYROLL'], $spreadsheet->getSheetNames());
        $this->assertSame(2_547_600, $this->totalOf($spreadsheet->getSheetByName('PAYROLL'), 'J'));

        $byDivision = $this->workbook($this->get('/api/v1/payroll/export?period=2026-10&group_by=division')->assertOk());
        $this->assertSame(['Bidang Tanaman', 'Tanpa Bidang', 'REKAP', 'PAYROLL'], $byDivision->getSheetNames());

        $this->getJson('/api/v1/payroll/export?period=2026-10&group_key=tidak-ada')
            ->assertUnprocessable()->assertJsonPath('errors.group_key.0', 'Kelompok tidak ditemukan di daftar upah periode ini');
    }

    public function test_locked_export_has_no_draft_mark_and_pdf_is_rendered(): void
    {
        $this->postJson('/api/v1/payroll/lock', ['period' => '2026-10'])->assertOk();

        $response = $this->get('/api/v1/payroll/export?period=2026-10')->assertOk();
        $this->assertStringContainsString(rawurlencode('Kelompok Daftar Upah - Oktober 2026.xlsx'), $response->headers->get('Content-Disposition'));
        $this->assertNull($this->workbook($response)->getSheetByName('Gunung Mas')->getCell('S3')->getValue());

        $pdf = $this->get('/api/v1/payroll/export?period=2026-10&format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $this->content($pdf));
        $this->assertStringContainsString(rawurlencode('Oktober 2026.pdf'), $pdf->headers->get('Content-Disposition'));
    }

    public function test_draft_pdf_export_is_rendered(): void
    {
        $pdf = $this->get('/api/v1/payroll/export?period=2026-10&format=pdf&group_by=division')->assertOk();

        $this->assertStringStartsWith('%PDF', $this->content($pdf));
        $this->assertStringContainsString(rawurlencode('Bidang - Oktober 2026 (DRAF).pdf'), $pdf->headers->get('Content-Disposition'));
    }

    public function test_spkl_excel_has_daily_rows_totals_and_paid_amount(): void
    {
        $response = $this->get('/api/v1/payroll/spkl?period=2026-10&type=reguler')->assertOk();
        $this->assertStringContainsString(rawurlencode('SPKL Lembur Reguler - Kelompok Daftar Upah - Oktober 2026 (DRAF).xlsx'), $response->headers->get('Content-Disposition'));

        $spreadsheet = $this->workbook($response);
        $this->assertSame(['Gunung Mas'], $spreadsheet->getSheetNames());   // hanya karyawan yang punya lembur reguler
        $sheet = $spreadsheet->getSheet(0);
        $this->assertSame('PTPN I (Persero) Regional 2', $sheet->getCell('A1')->getValue());   // disingkat supaya judul bisa tepat di tengah
        $this->assertSame(['Tahoma', 11.0], [$sheet->getStyle('A1')->getFont()->getName(), (float) $sheet->getStyle('A1')->getFont()->getSize()]);
        $this->assertSame('DAFTAR LEMBUR', $sheet->getCell('F1')->getValue());
        // Judul tepat di tengah: total lebar kolom di kiri gabungan judul = di kanannya.
        $widths = array_map(fn (string $letter) => $sheet->getColumnDimension($letter)->getWidth(), range('A', 'N'));
        [[$titleFrom], [$titleTo]] = Coordinate::rangeBoundaries(
            collect($sheet->getMergeCells())->first(fn (string $range) => str_starts_with($range, 'F1:')),
        );
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

        // Dicetak diperkecil: satu blok karyawan = satu halaman A4; lebar kolom mengikuti tulisan terpanjang (satu baris).
        $this->assertSame([PageSetup::PAPERSIZE_A4, 1, 1], [$sheet->getPageSetup()->getPaperSize(), $sheet->getPageSetup()->getFitToWidth(), $sheet->getPageSetup()->getFitToHeight()]);
        $this->assertGreaterThanOrEqual(mb_strlen('Setelah 8 Jam Pertama'), $sheet->getColumnDimension('I')->getWidth());

        $this->getJson('/api/v1/payroll/spkl?period=2026-10&type=dw')
            ->assertUnprocessable()->assertJsonPath('errors.period.0', 'Tidak ada lembur DW untuk dicetak pada pilihan ini');
    }

    public function test_spkl_pdf_for_selected_employees(): void
    {
        $response = $this->get("/api/v1/payroll/spkl?period=2026-10&type=dw&format=pdf&employee_ids={$this->ani->id},{$this->budi->id}")->assertOk();

        $this->assertStringStartsWith('%PDF', $this->content($response));
        $this->assertStringContainsString(rawurlencode('SPKL Lembur DW - Kelompok Daftar Upah - Oktober 2026 (DRAF).pdf'), $response->headers->get('Content-Disposition'));
    }

    public function test_large_spkl_pdf_is_rendered_in_parts_and_merged_into_one_file(): void
    {
        // Lebih dari satu bagian render: 25 karyawan baru + Ani = 26 karyawan = 26 halaman dalam SATU file.
        $ids = collect(range(1, PayrollExportController::SPKL_PDF_PAGES_PER_PART))->map(fn (int $number) => $this->casual("Karyawan {$number}")->id);
        $ids = [...$ids, $this->ani->id];

        $response = $this->get('/api/v1/payroll/spkl?period=2026-10&type=reguler&format=pdf&employee_ids='.implode(',', $ids))->assertOk();

        $this->assertSame(26, $this->pdfPageCount($response));
    }

    public function test_payroll_pdf_has_one_page_per_sheet_in_one_file(): void
    {
        $response = $this->get('/api/v1/payroll/export?period=2026-10&format=pdf')->assertOk();

        $this->assertSame(5, $this->pdfPageCount($response));   // Gunung Mas, Petugas Parkir, Tanpa Kelompok, REKAP, PAYROLL
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

    /** Nilai pada baris JUMLAH di kolom itu. */
    private function totalOf(Worksheet $sheet, string $column): int
    {
        for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
            if ($sheet->getCell("A{$row}")->getValue() === 'JUMLAH') {
                return (int) $sheet->getCell("{$column}{$row}")->getValue();
            }
        }
        $this->fail("Tidak ada baris JUMLAH di sheet {$sheet->getTitle()}");
    }

    private function firstValueInRow(Worksheet $sheet, int $row): mixed
    {
        foreach ($sheet->rangeToArray("A{$row}:U{$row}", null, false, false)[0] as $value) {
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
