<?php

namespace Tests\Feature\Payroll;

use App\Models\User;
use App\Services\Attendance\AttendanceListTemplateWorkbook;
use App\Services\Attendance\DwPay;
use App\Services\Attendance\TemplateRecapSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * Sheet "Rekap" di template Daftar Hadir / Lembur: angka rumus Excel-nya harus SAMA dengan perhitungan sistem
 * (GET /payroll & DwPay). Data uji di PayrollTestHelpers (Ani Harian batas 20 hari dengan K 22, Budi Flat Bulanan
 * aktif 15 hari, Cici tanpa skema).
 */
class TemplateRecapTest extends TestCase
{
    use PayrollTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-05 10:00:00');
        Sanctum::actingAs(User::factory()->create());
        $this->seedPayrollScenario();
    }

    public function test_attendance_recap_matches_payroll_base_pay(): void
    {
        $spreadsheet = $this->template('daftar_hadir');
        $this->setCodes($spreadsheet, $this->ani, [23 => 'DW', 24 => 'DW', 25 => 'DW', 26 => 'S']);
        $recap = $spreadsheet->getSheetByName(TemplateRecapSheet::SHEET_TITLE);

        $this->assertSame(['Daftar Hadir', 'Rekap', 'Petunjuk', 'Daftar'], $spreadsheet->getSheetNames());
        $this->assertSame(
            ['No. Registrasi', 'Nama', 'Gaji Pokok', 'Tarif / Hari', 'K', 'DW', 'S', 'I', 'C', 'M', 'L', 'Upah Hari Kerja', 'Upah DW'],
            array_map(fn (string $column) => $recap->getCell("{$column}4")->getValue(), ['C', 'D', 'H', 'J', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U']),
        );

        $payroll = $this->payrollRows();
        $ani = $this->recapRow($recap, 'A', $this->ani->id);
        $this->assertSame([2_600_000, 100_000, 22, 3, 1, 0], $this->values($recap, $ani, ['H', 'J', 'M', 'N', 'O', 'P']));
        $this->assertSame($payroll['Ani']['base_pay'], $this->value($recap, "T{$ani}"));   // 22 × 100.000 (tidak dibatasi; K diberi warna merah)
        $this->assertSame(2_200_000, $payroll['Ani']['base_pay']);
        $this->assertSame(300_000, $this->value($recap, "U{$ani}"));                        // 3 × 2.600.000 ÷ 26
        $this->assertSame('K 22 melebihi batas 20 hari', $recap->getCell("V{$ani}")->getCalculatedValue());

        // Jumlah K yang melebihi batas hari berwarna merah (conditional formatting kolom K rekap).
        $condition = $recap->getConditionalStyles('M5:M7')[0];
        $this->assertSame(['AND($K5<>"",$M5>$K5)'], $condition->getConditions());
        $this->assertSame(AttendanceListTemplateWorkbook::CODE_COLORS['M'][0], $condition->getStyle()->getFill()->getStartColor()->getRGB());

        $budi = $this->recapRow($recap, 'A', $this->budi->id);
        $this->assertSame($payroll['Budi']['base_pay'], $this->value($recap, "T{$budi}"));   // Flat Bulanan prorata 15/31
        $cici = $this->recapRow($recap, 'A', $this->cici->id);
        $this->assertSame([0, 'Skema upah belum diatur'], [$this->value($recap, "T{$cici}"), $recap->getCell("V{$cici}")->getCalculatedValue()]);

        $total = $this->recapRow($recap, 'B', 'JUMLAH');
        $this->assertSame($payroll['Ani']['base_pay'] + $payroll['Budi']['base_pay'], $this->value($recap, "T{$total}"));
        $this->assertTrue($recap->getProtection()->getSheet());
        $this->assertFalse($recap->getColumnDimension('A')->getVisible());   // ID Sistem disembunyikan
    }

    public function test_overtime_recap_matches_payroll_overtime_pay_per_date(): void
    {
        $spreadsheet = $this->template('lembur_reguler');
        $recap = $spreadsheet->getSheetByName(TemplateRecapSheet::SHEET_TITLE);
        $this->assertSame(['Lembur Reguler', 'Rekap', 'Petunjuk', 'Data Karyawan', 'Daftar'], $spreadsheet->getSheetNames());
        $this->assertSame(
            ['No. Registrasi', 'Nama', 'Gaji Pokok', 'Tarif / Jam', 'Total Jam', 'Total Uang Lembur'],
            array_map(fn (string $column) => $recap->getCell("{$column}4")->getValue(), ['B', 'C', 'F', 'G', 'H', 'I']),
        );

        // Lembur tersimpan: Ani 05/10 17:00–19:00 (2 jam) = Rp52.600 di Daftar Upah.
        $ani = $this->recapRow($recap, 'B', '7761');
        $this->assertSame([2_600_000, 15_029, 2], $this->values($recap, $ani, ['F', 'G', 'H']));
        $this->assertSame($this->payrollRows()['Ani']['regular_overtime_pay'], $this->value($recap, "I{$ani}"));

        // Tambah baris (No. Registrasi diketik sebagai ANGKA) pada tanggal yang sama: jam dijumlahkan per tanggal dulu.
        $sheet = $spreadsheet->getSheetByName('Lembur Reguler');
        $sheet->setCellValueExplicit('B6', 7761, DataType::TYPE_NUMERIC);
        $sheet->setCellValue('L6', ExcelDate::PHPToExcel(Carbon::parse('2026-10-05')));
        $sheet->setCellValue('M6', 20 / 24);
        $sheet->setCellValue('N6', 21 / 24);
        $spreadsheet->getCalculationEngine()->clearCalculationCache();

        $expected = DwPay::make()->payForMinutes($this->ani->wageSchemes()->first(), 180);   // 3 jam pada satu tanggal
        $this->assertSame([3, $expected], $this->values($recap, $ani, ['H', 'I']));

        $budi = $this->recapRow($recap, 'B', '7530');
        $this->assertSame([0, 0], $this->values($recap, $budi, ['H', 'I']));
        $cici = $this->recapRow($recap, 'B', '8001');
        $this->assertSame([0, 'Skema upah belum diatur'], [$this->value($recap, "I{$cici}"), $recap->getCell("J{$cici}")->getValue()]);
    }

    /** Nomor baris rekap yang kolomnya berisi nilai itu. */
    private function recapRow(Worksheet $recap, string $column, string $value): int
    {
        for ($row = TemplateRecapSheet::FIRST_DATA_ROW; $row <= $recap->getHighestRow(); $row++) {
            if ((string) $recap->getCell("{$column}{$row}")->getValue() === $value) {
                return $row;
            }
        }
        $this->fail("Tidak ada baris rekap dengan {$column} = {$value}");
    }

    private function value(Worksheet $recap, string $cell): int|float
    {
        $value = $recap->getCell($cell)->getCalculatedValue();

        return is_float($value) && floor($value) === $value ? (int) $value : $value;
    }

    /** @param  list<string>  $columns */
    private function values(Worksheet $recap, int $row, array $columns): array
    {
        return array_map(fn (string $column) => $this->value($recap, "{$column}{$row}"), $columns);
    }
}
