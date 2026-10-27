<?php

namespace Tests\Feature\Payroll;

use App\Models\User;
use App\Services\Payroll\PayrollExportDocument;
use Database\Seeders\SignatureSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
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
        $this->assertSame('PT PERKEBUNAN NUSANTARA I REGIONAL 2', $mountain->getCell('A1')->getValue());
        $this->assertSame(PayrollExportDocument::TITLE, $mountain->getCell('F1')->getValue());
        $this->assertSame('Bulan : Oktober 2026', $mountain->getCell('S1')->getValue());
        $this->assertSame('Kelompok Daftar Upah : Gunung Mas', $mountain->getCell('A3')->getValue());
        $this->assertSame('Sub Unit : Kebun Gunung Mas (Kode Afd 01)', $mountain->getCell('A4')->getValue());
        $this->assertSame(['HARI KERJA', 'HKE', 'Absen'], [$mountain->getCell('G6')->getValue(), $mountain->getCell('G7')->getValue(), $mountain->getCell('H7')->getValue()]);
        $this->assertSame(['Ani', 2_000_000, 2_347_600], [$mountain->getCell('C8')->getValue(), $mountain->getCell('K8')->getValue(), $mountain->getCell('P8')->getValue()]);
        $this->assertSame('JUMLAH', $mountain->getCell('A9')->getValue());
        $this->assertSame('Gunung Mas, 05 November 2026', $mountain->getCell('R11')->getValue());
        $this->assertSame('Disetujui', $this->firstValueInRow($mountain, 12));
        $this->assertSame('M. TAUFIK SIREGAR SE', $this->firstValueInRow($mountain, 18));

        $groupNet = 0;
        foreach (['Gunung Mas', 'Petugas Parkir', 'Tanpa Kelompok'] as $title) {
            $groupNet += $this->totalOf($spreadsheet->getSheetByName($title), 'U');
        }
        $rekapNet = $this->totalOf($spreadsheet->getSheetByName('REKAP'), 'R');
        $payrollNet = $this->totalOf($spreadsheet->getSheetByName('PAYROLL'), 'J');
        $this->assertSame(3_847_600, $groupNet);
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
        $this->assertSame(2_347_600, $this->totalOf($spreadsheet->getSheetByName('PAYROLL'), 'J'));

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
        $this->assertSame('PTP NUSANTARA I REGIONAL 2', $sheet->getCell('A1')->getValue());
        $this->assertSame('DAFTAR LEMBUR', $sheet->getCell('E1')->getValue());
        $this->assertSame(': Ani', $sheet->getCell('B3')->getValue());
        $this->assertSame(': Karyawan Casual', $sheet->getCell('B4')->getValue());
        $this->assertSame(': Pemandu Wisata', $sheet->getCell('B5')->getValue());
        $this->assertSame(': Rp 15.029', $sheet->getCell('K4')->getValue());

        // Baris data mulai 9 (1 Okt = Kamis); 5 Okt = baris 13.
        $this->assertSame(['Kamis', 1], [$sheet->getCell('A9')->getValue(), $sheet->getCell('B9')->getValue()]);
        $this->assertSame(['Senin', 5, '17:00', '19:00', 2, 1, 1, 0, 'Persiapan acara'], $sheet->rangeToArray('A13:I13', null, false, false)[0]);
        $this->assertSame('Jumlah :', $sheet->getCell('A40')->getValue());
        $this->assertEquals([2, 1, 1, 0], $sheet->rangeToArray('E40:H40', null, false, false)[0]);
        $this->assertEquals([1.5, 2, '-'], $sheet->rangeToArray('F41:H41', null, false, false)[0]);
        $this->assertSame('Jumlah jam yang dibayar : 3,5 x Rp. 15.029 = Rp 52.600', $sheet->getCell('A43')->getValue());
        $this->assertSame('Gunung Mas, 05 November 2026', $sheet->getCell('I44')->getValue());

        $this->getJson('/api/v1/payroll/spkl?period=2026-10&type=dw')
            ->assertUnprocessable()->assertJsonPath('errors.period.0', 'Tidak ada lembur DW untuk dicetak pada pilihan ini');
    }

    public function test_spkl_pdf_for_selected_employees(): void
    {
        $response = $this->get("/api/v1/payroll/spkl?period=2026-10&type=dw&format=pdf&employee_ids={$this->ani->id},{$this->budi->id}")->assertOk();

        $this->assertStringStartsWith('%PDF', $this->content($response));
        $this->assertStringContainsString(rawurlencode('SPKL Lembur DW - Kelompok Daftar Upah - Oktober 2026 (DRAF).pdf'), $response->headers->get('Content-Disposition'));
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
