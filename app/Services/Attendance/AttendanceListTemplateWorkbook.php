<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceCode;
use App\Enums\AttendanceImportType;
use App\Services\Employee\EmployeeExportWorkbook;
use App\Support\DocumentStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Menulis FILE EXCEL template DAFTAR HADIR satu periode (tata letak: AttendanceListLayout):
 *
 *   - satu baris per karyawan Casual & DW yang aktif dalam periode, sudah terisi kode yang tersimpan
 *   - sel tanggal: dropdown K, DW, I, S, M, C, L; Minggu & hari libur diarsir; tanggal tidak aktif abu-abu;
 *     kode berwarna (K hijau, DW kuning, C/I/S oranye, M/L merah)
 *   - dibekukan: judul (baris 1–4) & kolom sampai Jabatan
 *   - identitas: jabatan, sub unit, objek, kelompok upah, cost center & uraiannya (nilai yang berlaku)
 *   - kolom jumlah per kode = COUNTIF
 *   - baris 4 = baris filter abu-abu (tombol filter & urut); judul dikunci (sheet diproteksi tanpa sandi),
 *     baris data tidak dikunci supaya bisa diurutkan
 */
class AttendanceListTemplateWorkbook
{
    public const HOLIDAY_FILL = 'FCE4D6';

    /** Lebar kolom tanggal: cukup untuk nama hari ("Sen") & kode tebal ("DW") tanpa turun ke baris berikutnya. */
    public const DATE_COLUMN_WIDTH = 6.5;

    /**
     * Warna isian kode (conditional formatting, ikut berubah kalau kodenya diubah di Excel):
     * [kode => [latar, huruf]]. K hijau, DW kuning, C/I/S oranye, M/L merah.
     */
    public const CODE_COLORS = [
        'K' => ['C6EFCE', '006100'],
        'DW' => ['FFEB9C', '9C5700'],
        'C' => ['F4B084', '843C0C'],
        'I' => ['F4B084', '843C0C'],
        'S' => ['F4B084', '843C0C'],
        'M' => ['FFC7CE', '9C0006'],
        'L' => ['FFC7CE', '9C0006'],
    ];

    public const INACTIVE_FILL = 'BFBFBF';

    /**
     * @param  Collection<string, PeriodEmployee>  $employees
     * @param  array<string, array<string, string>>  $codes  [id karyawan][tanggal] => kode tersimpan
     * @param  array<string, string>  $holidays  [tanggal] => nama hari libur
     */
    public function write(AttendancePeriod $period, Collection $employees, array $codes, array $holidays): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName(DocumentStyle::FONT)->setSize(DocumentStyle::FONT_SIZE);
        $sheet = $spreadsheet->getActiveSheet()->setTitle(AttendanceListLayout::SHEET_TITLE);

        $dates = $period->dates();
        $days = count($dates);
        $lastDate = AttendanceListLayout::dateLetter($days);
        $lastColumn = AttendanceListLayout::countLetter($days, count(AttendanceCode::cases()) - 1);
        $first = AttendanceListLayout::FIRST_DATA_ROW;
        $lastRow = max($first, $first + $employees->count() - 1);

        $this->writeHeader($sheet, $period, $dates, $holidays, $lastColumn);
        TemplateFilterRow::apply($sheet, AttendanceListLayout::FILTER_ROW, AttendanceListLayout::HEADER_ROW, $lastColumn);
        $this->writeRows($sheet, $employees->values(), $dates, $codes, $holidays);
        $this->styleBody($sheet, $lastColumn, $lastRow);
        $this->addCodeDropdown($sheet, $lastDate, $lastRow);
        $this->addCodeColors($sheet, $lastDate, $lastRow);
        $this->writeListSheet($spreadsheet, $period);
        $this->writeGuideSheet($spreadsheet);
        TemplateRecapSheet::attendanceList($spreadsheet, $period, $employees);   // sheet ke-2, sesudah sheet data

        // Tombol filter & urut di baris filter; baris data tidak dikunci supaya bisa diurutkan,
        // judul (baris 1–4) tetap dikunci. Pencocokan karyawan tetap memakai ID Sistem di tiap baris.
        $sheet->setAutoFilter('A'.AttendanceListLayout::FILTER_ROW.":{$lastColumn}{$lastRow}");
        // Bekukan judul (baris 1–4) dan kolom identitas sampai Jabatan.
        $sheet->freezePane(Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString(AttendanceListLayout::LAST_FROZEN_COLUMN) + 1).$first);
        $sheet->getProtection()->setSheet(true)->setAutoFilter(false)->setSort(false)->setFormatColumns(false);
        $spreadsheet->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'template-daftar-hadir-');
        (new Xlsx($spreadsheet))->setPreCalculateFormulas(false)->save($path);   // rumus dihitung Excel saat dibuka
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /** @param  list<string>  $dates */
    private function writeHeader(Worksheet $sheet, AttendancePeriod $period, array $dates, array $holidays, string $lastColumn): void
    {
        $sheet->setCellValue('A'.AttendanceListLayout::TITLE_ROW, 'DAFTAR HADIR KARYAWAN CASUAL & DAILY WORKER — UNIT AGROWISATA — Periode: '.$period->label());
        $sheet->mergeCells('A1:'.$lastColumn.'1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->getRowDimension(1)->setRowHeight(24);

        $header = AttendanceListLayout::HEADER_ROW;
        $dayRow = AttendanceListLayout::DAY_NAME_ROW;
        foreach (AttendanceListLayout::IDENTITY_COLUMNS as [$letter, $title, $width]) {
            $sheet->setCellValue("{$letter}{$header}", $title);
            $sheet->mergeCells("{$letter}{$header}:{$letter}{$dayRow}");
            $sheet->getColumnDimension($letter)->setWidth($width);
        }
        $sheet->getColumnDimension('A')->setVisible(false);

        foreach ($dates as $index => $date) {
            $letter = AttendanceListLayout::dateLetter($index + 1);
            $carbon = Carbon::parse($date);
            $sheet->setCellValue("{$letter}{$header}", $carbon->day);
            $sheet->setCellValue("{$letter}{$dayRow}", AttendancePeriod::DAY_NAMES[$carbon->dayOfWeek]);
            $sheet->getColumnDimension($letter)->setWidth(self::DATE_COLUMN_WIDTH);
            if ($carbon->isSunday() || isset($holidays[$date])) {
                $sheet->getStyle("{$letter}{$header}:{$letter}{$dayRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HOLIDAY_FILL);
            }
        }

        foreach (AttendanceCode::values() as $index => $code) {
            $letter = AttendanceListLayout::countLetter(count($dates), $index);
            $sheet->setCellValue("{$letter}{$header}", $code);
            $sheet->mergeCells("{$letter}{$header}:{$letter}{$dayRow}");
            $sheet->getColumnDimension($letter)->setWidth(5);
        }

        $sheet->getStyle("A{$header}:{$lastColumn}{$dayRow}")->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        $identityEnd = AttendanceListLayout::IDENTITY_COLUMNS['cost_center_name'][0];
        $sheet->getStyle("A{$header}:{$identityEnd}{$dayRow}")->applyFromArray([
            'font' => ['color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => EmployeeExportWorkbook::HEADER_BLUE]],
        ]);
    }

    /**
     * @param  Collection<int, PeriodEmployee>  $employees
     * @param  list<string>  $dates
     */
    private function writeRows(Worksheet $sheet, Collection $employees, array $dates, array $codes, array $holidays): void
    {
        $days = count($dates);
        foreach ($employees as $index => $item) {
            $row = AttendanceListLayout::FIRST_DATA_ROW + $index;
            $values = [
                'employee_id' => $item->employee->id,
                'no' => $index + 1,
                'registration_number' => $item->employee->registration_number,
                'name' => $item->employee->name,
                'employment_status' => $item->employee->employment_status,
                'position' => $item->positionTitle(),
                'sub_unit' => $item->subUnit()['name'] ?? null,
                'afdeling_code' => $item->subUnit()['afdeling_code'] ?? null,
                'placement_objects' => $item->placementObjectNames() ?: null,
                'payroll_group' => $item->payrollGroupName(),
                'cost_center_code' => $item->costCenter?->costCenter->code,
                'cost_center_name' => $item->costCenter?->costCenter->name,
            ];
            foreach (AttendanceListLayout::IDENTITY_COLUMNS as $key => [$letter]) {
                if ($values[$key] !== null) {
                    is_string($values[$key])
                        ? $sheet->setCellValueExplicit("{$letter}{$row}", $values[$key], DataType::TYPE_STRING)
                        : $sheet->setCellValue("{$letter}{$row}", $values[$key]);
                }
            }

            foreach ($dates as $dayIndex => $date) {
                $cell = AttendanceListLayout::dateLetter($dayIndex + 1).$row;
                if (isset($codes[$item->employee->id][$date])) {
                    $sheet->setCellValueExplicit($cell, $codes[$item->employee->id][$date], DataType::TYPE_STRING);
                }
                $fill = ! $item->isActiveOn($date) ? self::INACTIVE_FILL
                    : (Carbon::parse($date)->isSunday() || isset($holidays[$date]) ? self::HOLIDAY_FILL : null);
                if ($fill !== null) {
                    $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
                }
            }

            $firstDate = AttendanceListLayout::dateLetter(1);
            $lastDate = AttendanceListLayout::dateLetter($days);
            foreach (AttendanceCode::values() as $codeIndex => $code) {
                $sheet->setCellValueExplicit(AttendanceListLayout::countLetter($days, $codeIndex).$row, "=COUNTIF({$firstDate}{$row}:{$lastDate}{$row},\"{$code}\")", DataType::TYPE_FORMULA);
            }
        }
    }

    private function styleBody(Worksheet $sheet, string $lastColumn, int $lastRow): void
    {
        $first = AttendanceListLayout::FIRST_DATA_ROW;
        $sheet->getStyle("A{$first}:{$lastColumn}{$lastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'A6A6A6']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            // Excel hanya bisa mengurutkan sel yang tidak dikunci pada sheet yang diproteksi.
            'protection' => ['locked' => Protection::PROTECTION_UNPROTECTED],
        ]);
        $firstDate = AttendanceListLayout::dateLetter(1);
        $sheet->getStyle("{$firstDate}{$first}:{$lastColumn}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /** Warnai sel tanggal menurut kodenya (lihat CODE_COLORS). */
    private function addCodeColors(Worksheet $sheet, string $lastDate, int $lastRow): void
    {
        $conditions = [];
        foreach (self::CODE_COLORS as $code => [$fill, $font]) {
            $condition = (new Conditional)
                ->setConditionType(Conditional::CONDITION_CELLIS)
                ->setOperatorType(Conditional::OPERATOR_EQUAL)
                ->addCondition("\"{$code}\"");
            $condition->getStyle()->getFill()->setFillType(Fill::FILL_SOLID);
            $condition->getStyle()->getFill()->getStartColor()->setRGB($fill);
            $condition->getStyle()->getFill()->getEndColor()->setRGB($fill);
            $condition->getStyle()->getFont()->setBold(true)->getColor()->setRGB($font);
            $conditions[] = $condition;
        }

        $sheet->getStyle(AttendanceListLayout::dateLetter(1).AttendanceListLayout::FIRST_DATA_ROW.":{$lastDate}{$lastRow}")
            ->setConditionalStyles($conditions);
    }

    private function addCodeDropdown(Worksheet $sheet, string $lastDate, int $lastRow): void
    {
        $count = count(AttendanceCode::cases());
        $validation = (new DataValidation)
            ->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(true)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Kode tidak dikenal')
            ->setError('Isi dengan K, DW, I, S, M, C, atau L (kosongkan kalau tidak masuk).')
            ->setFormula1("'".AttendanceListLayout::LIST_SHEET_TITLE."'!\$A\$2:\$A\$".($count + 1));

        $sheet->setDataValidation(AttendanceListLayout::dateLetter(1).AttendanceListLayout::FIRST_DATA_ROW.":{$lastDate}{$lastRow}", $validation);
    }

    private function writeListSheet(Spreadsheet $spreadsheet, AttendancePeriod $period): void
    {
        $sheet = $spreadsheet->createSheet()->setTitle(AttendanceListLayout::LIST_SHEET_TITLE);
        $sheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $sheet->setCellValue('A1', 'Kode');
        foreach (AttendanceCode::values() as $index => $code) {
            $sheet->setCellValueExplicit('A'.($index + 2), $code, DataType::TYPE_STRING);
        }
        $sheet->setCellValue('D1', 'Periode');
        $sheet->setCellValueExplicit(AttendanceListLayout::PERIOD_CELL, $period->period, DataType::TYPE_STRING);
        $sheet->setCellValue('D2', 'Jenis');
        $sheet->setCellValueExplicit(AttendanceListLayout::TYPE_CELL, AttendanceImportType::AttendanceList->value, DataType::TYPE_STRING);
    }

    private function writeGuideSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet(1)->setTitle(AttendanceListLayout::GUIDE_SHEET_TITLE);
        $sheet->getColumnDimension('A')->setWidth(110);
        $lines = [
            'PETUNJUK PENGISIAN DAFTAR HADIR',
            '',
            '1. Isi sel tanggal dengan kode di bawah (pilih dari daftar). Sel KOSONG = tidak masuk, tidak dibayar.',
            '2. Jangan mengubah judul, urutan kolom, nama sheet, atau periode. Kolom ID Sistem (tersembunyi) adalah kunci pencocokan karyawan.',
            '   Gunakan baris abu-abu (baris 4) untuk filter & urut. Kolom identitas (nama, jabatan, kelompok upah, cost center, dst.) hanya informasi; mengubahnya tidak berpengaruh.',
            '3. Kolom abu-abu = tanggal ketika karyawan tidak aktif; jangan diisi. Kolom oranye muda = hari Minggu / hari libur.',
            '   Warna kode: K hijau, DW kuning, C / I / S oranye, M / L merah.',
            '4. Mengunggah ulang MENGGANTIKAN seluruh data hasil unggahan sebelumnya pada periode ini (sel yang dikosongkan ikut dikosongkan).',
            '   Data dari aplikasi mobile & koreksi manual tidak ditimpa; perbedaannya ditampilkan sebagai KONFLIK.',
            '5. Unggah daftar hadir LEBIH DULU sebelum lembur, karena lembur diperiksa terhadap kode daftar hadir yang tersimpan.',
            '',
            'ARTI KODE',
        ];
        foreach (AttendanceCode::cases() as $code) {
            $lines[] = "  {$code->value} = {$code->label()}".match ($code) {
                AttendanceCode::Work => ' — satu-satunya kode yang DIBAYAR & dihitung uang makan',
                AttendanceCode::DailyWorker => ' — alat bantu admin (hari kerja di atas batas), tidak dihitung upah harian',
                default => '',
            };
        }
        foreach ($lines as $index => $line) {
            $sheet->setCellValue('A'.($index + 1), $line);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
    }
}
