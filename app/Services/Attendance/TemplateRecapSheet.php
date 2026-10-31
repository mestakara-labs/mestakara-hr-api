<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceCode;
use App\Enums\WageType;
use App\Models\OvertimeSetting;
use App\Services\Employee\EmployeeExportWorkbook;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * SHEET "REKAP" di template Daftar Hadir / Lembur Reguler / Lembur DW: rekapitulasi per karyawan yang IKUT BERUBAH
 * ketika sheet data diisi (rumus Excel). Hanya informasi; tidak dibaca saat unggah.
 *
 *   Daftar Hadir   Gaji Pokok, tarif/hari (Gaji Pokok ÷ HKE), jumlah K DW S I C M L (dari kolom jumlah di sheet data,
 *                  dicari lewat ID Sistem sehingga tetap benar walaupun sheet data diurutkan), upah hari kerja, upah DW
 *                    upah hari kerja  Harian = K × tarif/hari (TIDAK dibatasi; K melebihi batas hari diberi warna merah);
 *                                     Flat Bulanan = round(Gaji Pokok × hari aktif ÷ hari kalender)
 *                    upah DW          round(DW × Gaji Pokok ÷ HKE)
 *   Lembur         Gaji Pokok, tarif/jam (round(Gaji Pokok ÷ pembagi)), total jam, total uang lembur
 *                    upah per TANGGAL = tarif × (min(jam, 1) × pengali pertama + max(jam − 1, 0) × pengali berikutnya),
 *                    dibulatkan ke kelipatan pembulatan (aturan sama dengan DwPay); jam per tanggal ada di kolom
 *                    tersembunyi di kanan, upahnya dijumlahkan dengan SUMPRODUCT
 *                    (min(jam,1) = jam − (jam>1)×(jam−1); max(jam−1,0) = (jam>1)×(jam−1))
 *
 * Skema upah = yang berlaku pada tanggal acuan periode (sama dengan Daftar Upah). Sheet dikunci (hanya dibaca).
 */
class TemplateRecapSheet
{
    public const SHEET_TITLE = 'Rekap';

    public const HEADER_ROW = 4;

    public const FIRST_DATA_ROW = 5;

    private const MONEY_FORMAT = '#,##0';

    /** Urutan kode di rekap daftar hadir. */
    private const CODE_ORDER = ['K', 'DW', 'S', 'I', 'C', 'M', 'L'];

    /**
     * Rekap template Daftar Hadir (sheet data: AttendanceListLayout).
     *
     * @param  Collection<int, PeriodEmployee>  $employees  urutan sama dengan sheet data
     */
    public static function attendanceList(Spreadsheet $spreadsheet, AttendancePeriod $period, Collection $employees): void
    {
        $days = count($period->dates());
        $data = "'".AttendanceListLayout::SHEET_TITLE."'!";
        $idColumn = $data.'$A:$A';

        // [judul, lebar, rupiah?]
        $columns = [
            'A' => ['ID Sistem', 38, false], 'B' => ['No', 5, false], 'C' => ['No. Registrasi', 14, false],
            'D' => ['Nama', 30, false], 'E' => ['Jabatan', 26, false], 'F' => ['Kelompok Upah', 18, false],
            'G' => ['Jenis Upah', 13, false], 'H' => ['Gaji Pokok', 13, true], 'I' => ['HKE', 6, false],
            'J' => ['Tarif / Hari', 11, true], 'K' => ['Batas Hari', 7, false], 'L' => ['Hari Aktif', 7, false],
        ];
        $codeLetters = [];
        foreach (self::CODE_ORDER as $index => $code) {
            $letter = Coordinate::stringFromColumnIndex(13 + $index);   // M, N, …
            $codeLetters[$code] = $letter;
            $columns[$letter] = [$code, 6, false];
        }
        $columns += ['T' => ['Upah Hari Kerja', 15, true], 'U' => ['Upah DW', 14, true], 'V' => ['Keterangan', 32, false]];

        $sheet = self::createSheet($spreadsheet, 'REKAP DAFTAR HADIR — Periode: '.$period->label(),
            'Upah hari kerja: Harian = K × tarif/hari (K melebihi batas hari berwarna merah); Flat Bulanan = Gaji Pokok × hari aktif ÷ '
            .$days.' hari. Upah DW = DW × Gaji Pokok ÷ HKE. Angka ikut berubah saat sheet '.AttendanceListLayout::SHEET_TITLE.' diisi.',
            $columns);
        $sheet->getColumnDimension('A')->setVisible(false);

        $row = self::FIRST_DATA_ROW;
        foreach ($employees->values() as $index => $item) {
            $scheme = $item->scheme;
            self::text($sheet, "A{$row}", $item->employee->id);
            $sheet->setCellValue("B{$row}", $index + 1);
            self::text($sheet, "C{$row}", $item->employee->registration_number);
            self::text($sheet, "D{$row}", $item->employee->name);
            self::text($sheet, "E{$row}", $item->positionTitle());
            self::text($sheet, "F{$row}", $item->payrollGroupName());
            self::text($sheet, "G{$row}", $scheme?->wage_type->value);
            if ($scheme !== null) {
                $sheet->setCellValue("H{$row}", $scheme->base_salary);
                $sheet->setCellValue("I{$row}", $scheme->working_days);
                self::formula($sheet, "J{$row}", "=ROUND(H{$row}/I{$row},0)");
                if ($item->capDays() !== null) {
                    $sheet->setCellValue("K{$row}", $item->capDays());
                }
            }
            $sheet->setCellValue("L{$row}", count($item->activeDates()));

            foreach ($codeLetters as $code => $letter) {
                $countColumn = AttendanceListLayout::countLetter($days, array_search($code, AttendanceCode::values(), true));
                self::formula($sheet, "{$letter}{$row}", "=IFERROR(INDEX({$data}\${$countColumn}:\${$countColumn},MATCH(\$A{$row},{$idColumn},0)),0)");
            }
            [$k, $dw] = [$codeLetters['K'].$row, $codeLetters['DW'].$row];
            self::formula($sheet, "T{$row}", "=IF(H{$row}=\"\",0,IF(G{$row}=\"".WageType::Daily->value."\",{$k}*J{$row},ROUND(H{$row}*L{$row}/{$days},0)))");
            self::formula($sheet, "U{$row}", "=IF(H{$row}=\"\",0,ROUND({$dw}*H{$row}/I{$row},0))");
            self::formula($sheet, "V{$row}", "=IF(H{$row}=\"\",\"Skema upah belum diatur\",IF(AND(K{$row}<>\"\",{$k}>K{$row}),\"K \"&{$k}&\" melebihi batas \"&K{$row}&\" hari\",\"\"))");
            $row++;
        }

        self::markOverCap($sheet, $codeLetters['K'], $row - 1);
        self::finish($sheet, $columns, $row, [...array_values($codeLetters), 'T', 'U'], 'E');
    }

    /**
     * Rekap template Lembur Reguler / Lembur DW (sheet data: OvertimeLayout). Satu baris per karyawan yang punya
     * No. Registrasi (sama dengan sheet "Data Karyawan").
     *
     * @param  Collection<int, PeriodEmployee>  $employees
     */
    public static function overtime(Spreadsheet $spreadsheet, AttendancePeriod $period, string $dataSheetTitle, Collection $employees): void
    {
        $settings = OvertimeSetting::current();
        $dates = $period->dates();
        $data = "'{$dataSheetTitle}'!";
        $registrations = $data.'$'.OvertimeLayout::letter('registration_number').':$'.OvertimeLayout::letter('registration_number');
        $dateColumn = $data.'$'.OvertimeLayout::letter('date').':$'.OvertimeLayout::letter('date');
        $durationColumn = $data.'$'.OvertimeLayout::letter('duration').':$'.OvertimeLayout::letter('duration');
        $first = self::number($settings->first_hour_multiplier);
        $next = self::number($settings->next_hour_multiplier);
        $step = max((int) $settings->rounding, 1);

        $columns = [
            'A' => ['No', 5, false], 'B' => ['No. Registrasi', 14, false], 'C' => ['Nama', 30, false],
            'D' => ['Jabatan', 26, false], 'E' => ['Kelompok Upah', 18, false], 'F' => ['Gaji Pokok', 13, true],
            'G' => ['Tarif / Jam', 11, true], 'H' => ['Total Jam', 10, false], 'I' => ['Total Uang Lembur', 16, true],
            'J' => ['Keterangan', 26, false],
        ];
        // Kolom bantu tersembunyi: jam per tanggal.
        $hourLetters = [];
        foreach ($dates as $index => $date) {
            $hourLetters[$date] = Coordinate::stringFromColumnIndex(11 + $index);
            $day = (int) substr($date, 8, 2);
            $columns[$hourLetters[$date]] = ["Jam tgl {$day}", 8, false];
        }

        $sheet = self::createSheet($spreadsheet, 'REKAP '.mb_strtoupper($dataSheetTitle).' — Periode: '.$period->label(),
            'Tarif/jam = Gaji Pokok ÷ '.$settings->hourly_divisor.'. Upah per tanggal = tarif × (jam pertama × '.str_replace('.', ',', $first)
            .' + jam berikutnya × '.str_replace('.', ',', $next).'), dibulatkan Rp'.number_format($step, 0, ',', '.')
            .'. Angka ikut berubah saat sheet '.$dataSheetTitle.' diisi.',
            $columns);

        $row = self::FIRST_DATA_ROW;
        foreach ($employees->filter(fn (PeriodEmployee $item) => $item->employee->registration_number !== null)->values() as $index => $item) {
            $scheme = $item->scheme;
            $sheet->setCellValue("A{$row}", $index + 1);
            self::text($sheet, "B{$row}", $item->employee->registration_number);
            self::text($sheet, "C{$row}", $item->employee->name);
            self::text($sheet, "D{$row}", $item->positionTitle());
            self::text($sheet, "E{$row}", $item->payrollGroupName());
            if ($scheme !== null) {
                $sheet->setCellValue("F{$row}", $scheme->base_salary);
                self::formula($sheet, "G{$row}", "=ROUND(F{$row}/{$settings->hourly_divisor},0)");
            } else {
                self::text($sheet, "J{$row}", 'Skema upah belum diatur');
            }

            foreach ($dates as $date) {
                [$year, $month, $day] = array_map('intval', explode('-', $date));
                $hours = $hourLetters[$date].$row;
                self::formula($sheet, $hours, "=ROUND(SUMIFS({$durationColumn},{$registrations},\$B{$row},{$dateColumn},DATE({$year},{$month},{$day}))*1440,0)/60");
            }
            $hours = reset($hourLetters).$row.':'.end($hourLetters).$row;
            $extra = "({$hours}>1)*({$hours}-1)";   // jam sesudah jam pertama, per tanggal
            self::formula($sheet, "H{$row}", "=SUM({$hours})");
            self::formula($sheet, "I{$row}", "=IF(\$G{$row}=\"\",0,SUMPRODUCT(ROUND(\$G{$row}*(({$hours}-{$extra})*{$first}+{$extra}*{$next})/{$step},0)*{$step}))");
            $row++;
        }

        foreach ($hourLetters as $letter) {
            $sheet->getColumnDimension($letter)->setVisible(false);
        }
        self::finish($sheet, $columns, $row, ['H', 'I'], 'C');
    }

    /**
     * Jumlah K yang melebihi batas hari (kolom K) diberi warna MERAH (sama dengan warna kode M/L di sheet data).
     * Conditional formatting, jadi warnanya ikut berubah saat kode di sheet data diubah.
     */
    private static function markOverCap(Worksheet $sheet, string $kLetter, int $lastRow): void
    {
        if ($lastRow < self::FIRST_DATA_ROW) {
            return;
        }

        $first = self::FIRST_DATA_ROW;
        [$fill, $font] = AttendanceListTemplateWorkbook::CODE_COLORS['M'];
        $condition = (new Conditional)
            ->setConditionType(Conditional::CONDITION_EXPRESSION)
            ->addCondition("AND(\$K{$first}<>\"\",\${$kLetter}{$first}>\$K{$first})");
        $condition->getStyle()->getFill()->setFillType(Fill::FILL_SOLID);
        $condition->getStyle()->getFill()->getStartColor()->setRGB($fill);
        $condition->getStyle()->getFill()->getEndColor()->setRGB($fill);
        $condition->getStyle()->getFont()->setBold(true)->getColor()->setRGB($font);
        $sheet->getStyle("{$kLetter}{$first}:{$kLetter}{$lastRow}")->setConditionalStyles([$condition]);
    }

    /** Sheet "Rekap" (sesudah sheet data) + judul, keterangan rumus, dan judul kolom. */
    private static function createSheet(Spreadsheet $spreadsheet, string $title, string $note, array $columns): Worksheet
    {
        $sheet = $spreadsheet->createSheet(1)->setTitle(self::SHEET_TITLE);
        $lastColumn = array_key_last($columns);

        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->setCellValue('A2', $note);
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('595959');

        $header = self::HEADER_ROW;
        foreach ($columns as $letter => [$label, $width]) {
            $sheet->setCellValue("{$letter}{$header}", $label);
            $sheet->getColumnDimension($letter)->setWidth($width);
        }
        $sheet->getStyle("A{$header}:{$lastColumn}{$header}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => EmployeeExportWorkbook::HEADER_BLUE]],
        ]);
        $sheet->getRowDimension($header)->setRowHeight(30);

        return $sheet;
    }

    /**
     * Baris JUMLAH, garis, format rupiah, bekukan judul & kolom sampai $frozenUntil, lalu kunci sheet (hanya dibaca).
     *
     * @param  list<string>  $sumLetters  kolom yang dijumlahkan
     */
    private static function finish(Worksheet $sheet, array $columns, int $totalRow, array $sumLetters, string $frozenUntil): void
    {
        $first = self::FIRST_DATA_ROW;
        $lastData = $totalRow - 1;
        $lastColumn = array_key_last($columns);

        $sheet->setCellValue("B{$totalRow}", 'JUMLAH');
        foreach ($sumLetters as $letter) {
            self::formula($sheet, "{$letter}{$totalRow}", $lastData < $first ? '=0' : "=SUM({$letter}{$first}:{$letter}{$lastData})");
        }
        $sheet->getStyle("A{$totalRow}:{$lastColumn}{$totalRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$first}:{$lastColumn}{$totalRow}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('A6A6A6');

        foreach ($columns as $letter => [, , $isMoney]) {
            if ($isMoney) {
                $sheet->getStyle("{$letter}{$first}:{$letter}{$totalRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            }
        }

        $sheet->freezePane(Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($frozenUntil) + 1).$first);
        $sheet->getProtection()->setSheet(true)->setFormatColumns(false);
    }

    private static function text(Worksheet $sheet, string $cell, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
        }
    }

    private static function formula(Worksheet $sheet, string $cell, string $formula): void
    {
        $sheet->setCellValueExplicit($cell, $formula, DataType::TYPE_FORMULA);
    }

    /** Angka untuk rumus Excel (titik desimal), mis. 1.5. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
