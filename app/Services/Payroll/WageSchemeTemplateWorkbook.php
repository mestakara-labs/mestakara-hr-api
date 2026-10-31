<?php

namespace App\Services\Payroll;

use App\Enums\EmploymentStatus;
use App\Services\Employee\EmployeeExportWorkbook;
use App\Support\DocumentStyle;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Menulis FILE EXCEL template impor Skema Upah:
 *
 *   sheet "Skema Upah"  baris 1 judul kolom, baris 2 dst. satu karyawan per baris (kolom: WageSchemeTemplateColumns)
 *                       - ID Sistem tersembunyi; kolom bertanda * & ID dikunci (sheet diproteksi tanpa sandi)
 *                       - kolom skema upah dikunci & abu-abu untuk karyawan Tetap / PKWT
 *                       - dropdown: Jenis Upah, Ya/Tidak, Kelompok Daftar Upah, Kode Cost Center
 *   sheet "Petunjuk"    cara mengisi
 *   sheet "Daftar"      (tersembunyi) sumber pilihan dropdown
 *
 * Hasilnya file sementara (.xlsx) yang dihapus setelah dikirim ke browser.
 */
class WageSchemeTemplateWorkbook
{
    /** Latar sel yang dikunci (info) dan kolom skema upah karyawan Tetap / PKWT. */
    private const LOCKED_GREY = 'F2F2F2';

    private const NOT_APPLICABLE_GREY = 'BFBFBF';

    private const FORMATS = [
        'text' => NumberFormat::FORMAT_TEXT,
        'money' => '#,##0',
        'number' => '0',
        'date' => 'dd/mm/yyyy',
    ];

    /**
     * @param  list<array<string, mixed>>  $rows  dari WageSchemeTemplateService::rows()
     * @param  array<string, array{title: string, values: list<string>}>  $lists  dari WageSchemeTemplateService::lists()
     */
    public function write(array $rows, array $lists): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName(DocumentStyle::FONT)->setSize(DocumentStyle::FONT_SIZE);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(WageSchemeTemplateColumns::SHEET_TITLE);

        $lastRow = max(WageSchemeTemplateColumns::FIRST_DATA_ROW, WageSchemeTemplateColumns::FIRST_DATA_ROW + count($rows) - 1);

        $this->writeHeader($sheet);
        $this->writeRows($sheet, $rows);
        $this->styleColumns($sheet, $lastRow);
        $this->greyOutWageColumns($sheet, $rows);
        $listRanges = $this->writeListSheet($spreadsheet, $lists);
        $this->addDropdowns($sheet, $listRanges, $lastRow);
        $this->writeGuideSheet($spreadsheet);

        $sheet->freezePane('F'.WageSchemeTemplateColumns::FIRST_DATA_ROW);
        $sheet->setAutoFilter('A1:'.WageSchemeTemplateColumns::lastLetter().$lastRow);
        $this->protect($sheet);
        $spreadsheet->setActiveSheetIndex(0);
        $sheet->setSelectedCell('F'.WageSchemeTemplateColumns::FIRST_DATA_ROW);

        $path = tempnam(sys_get_temp_dir(), 'template-skema-upah-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function writeHeader(Worksheet $sheet): void
    {
        foreach (WageSchemeTemplateColumns::COLUMNS as $column) {
            $sheet->setCellValue($column['letter'].'1', $column['header']);
            $dimension = $sheet->getColumnDimension($column['letter']);
            $dimension->setWidth($column['width']);
            $dimension->setVisible($column['group'] !== 'id');
        }

        $sheet->getStyle('A1:'.WageSchemeTemplateColumns::lastLetter().'1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => EmployeeExportWorkbook::HEADER_BLUE]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(45);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function writeRows(Worksheet $sheet, array $rows): void
    {
        foreach ($rows as $index => $row) {
            $rowNumber = WageSchemeTemplateColumns::FIRST_DATA_ROW + $index;

            foreach (WageSchemeTemplateColumns::COLUMNS as $column) {
                $value = $row[$column['key']] ?? null;
                $cell = $column['letter'].$rowNumber;

                if ($value === null || $value === '') {
                    continue;
                }

                if ($value instanceof DateTimeInterface) {
                    $sheet->setCellValue($cell, ExcelDate::PHPToExcel($value));
                } elseif (is_string($value)) {
                    // Teks ditulis apa adanya (No. Registrasi / No. Rekening tidak berubah menjadi angka).
                    $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($cell, $value);
                }
            }
        }
    }

    /** Format isi, border, dan kunci per kolom (sel kosong di kolom teks tetap berformat teks). */
    private function styleColumns(Worksheet $sheet, int $lastRow): void
    {
        $first = WageSchemeTemplateColumns::FIRST_DATA_ROW;

        foreach (WageSchemeTemplateColumns::COLUMNS as $column) {
            $isLocked = in_array($column['group'], ['id', 'info'], true);

            $style = [
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'A6A6A6']]],
                'numberFormat' => ['formatCode' => self::FORMATS[$column['format']] ?? NumberFormat::FORMAT_GENERAL],
                'protection' => ['locked' => $isLocked ? Protection::PROTECTION_PROTECTED : Protection::PROTECTION_UNPROTECTED],
            ];
            if ($isLocked) {
                $style['fill'] = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LOCKED_GREY]];
            }
            if (in_array($column['format'], ['date', 'number'], true) || $column['list'] === 'yes_no') {
                $style['alignment']['horizontal'] = Alignment::HORIZONTAL_CENTER;
            }

            $sheet->getStyle("{$column['letter']}{$first}:{$column['letter']}{$lastRow}")->applyFromArray($style);
        }
    }

    /**
     * Kolom skema upah di baris karyawan Tetap / PKWT: dikunci & abu-abu (skema upah hanya untuk Casual & DW).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function greyOutWageColumns(Worksheet $sheet, array $rows): void
    {
        $firstWage = WageSchemeTemplateColumns::letter(WageSchemeTemplateColumns::WAGE_KEYS[0]);
        $lastWage = WageSchemeTemplateColumns::letter(WageSchemeTemplateColumns::WAGE_KEYS[array_key_last(WageSchemeTemplateColumns::WAGE_KEYS)]);
        $dailyWageStatuses = EmploymentStatus::dailyWageValues();

        foreach ($rows as $index => $row) {
            if (in_array($row['employment_status'], $dailyWageStatuses, true)) {
                continue;
            }

            $rowNumber = WageSchemeTemplateColumns::FIRST_DATA_ROW + $index;
            $sheet->getStyle("{$firstWage}{$rowNumber}:{$lastWage}{$rowNumber}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::NOT_APPLICABLE_GREY]],
                'protection' => ['locked' => Protection::PROTECTION_PROTECTED],
            ]);
        }
    }

    /**
     * Sheet "Daftar" (tersembunyi): satu kolom per daftar pilihan. Hasilnya [nama daftar => rentang sel], mis.
     * "'Daftar'!$A$2:$A$3". Daftar kosong tidak punya rentang.
     *
     * @param  array<string, array{title: string, values: list<string>}>  $lists
     * @return array<string, string>
     */
    private function writeListSheet(Spreadsheet $spreadsheet, array $lists): array
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(WageSchemeTemplateColumns::LIST_SHEET_TITLE);
        $sheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $ranges = [];
        $columnIndex = 1;
        foreach ($lists as $name => $list) {
            $letter = chr(ord('A') + $columnIndex - 1);
            $sheet->setCellValue("{$letter}1", $list['title']);
            foreach ($list['values'] as $offset => $value) {
                $sheet->setCellValueExplicit($letter.($offset + 2), $value, DataType::TYPE_STRING);
            }
            if ($list['values'] !== []) {
                $ranges[$name] = "'".WageSchemeTemplateColumns::LIST_SHEET_TITLE."'!\${$letter}\$2:\${$letter}\$".(count($list['values']) + 1);
            }
            $sheet->getColumnDimension($letter)->setWidth(28);
            $columnIndex++;
        }

        return $ranges;
    }

    /** @param  array<string, string>  $listRanges */
    private function addDropdowns(Worksheet $sheet, array $listRanges, int $lastRow): void
    {
        foreach (WageSchemeTemplateColumns::COLUMNS as $column) {
            if ($column['list'] === null || ! isset($listRanges[$column['list']])) {
                continue;
            }

            $validation = new DataValidation;
            $validation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_STOP)
                ->setAllowBlank(true)
                ->setShowDropDown(true)
                ->setShowErrorMessage(true)
                ->setErrorTitle('Pilihan tidak dikenal')
                ->setError("Pilih {$column['header']} dari daftar.")
                ->setFormula1($listRanges[$column['list']]);

            $sheet->setDataValidation("{$column['letter']}".WageSchemeTemplateColumns::FIRST_DATA_ROW.":{$column['letter']}{$lastRow}", $validation);
        }
    }

    private function writeGuideSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet(1);
        $sheet->setTitle(WageSchemeTemplateColumns::GUIDE_SHEET_TITLE);
        $sheet->getColumnDimension('A')->setWidth(120);

        $lines = [
            'PETUNJUK PENGISIAN TEMPLATE SKEMA UPAH',
            '',
            '1. Isi / ubah nilai di sheet "Skema Upah", lalu unggah kembali file ini di halaman Skema Upah (Pratinjau, lalu Simpan).',
            '2. Jangan mengubah judul kolom, urutan kolom, atau nama sheet. Kolom ID Sistem (tersembunyi) adalah kunci pencocokan karyawan.',
            '3. Kolom bertanda * (Nama, Status, Sub Unit) hanya informasi dan tidak bisa diubah.',
            '4. Skema upah (Jenis Upah s.d. Kelompok Daftar Upah) HANYA untuk Karyawan Casual & Daily Worker. Di baris Tetap / PKWT kolom ini berwarna abu-abu dan dikunci.',
            '5. Rekening (Nama Bank, No. Rekening, Atas Nama) & Kode Cost Center boleh diisi untuk semua karyawan. Rekening harus diisi lengkap bertiga.',
            '',
            'ISIAN SKEMA UPAH',
            '- Jenis Upah: Harian atau Flat Bulanan (pilih dari daftar). Wajib kalau skema diisi.',
            '- Gaji Pokok: rupiah, lebih dari 0, tanpa titik/koma. Wajib kalau skema diisi.',
            '- HKE: 1–31; kosong = 25.',
            '- Dibatasi 20 Hari: Ya / Tidak; kosong = Tidak.',
            '- Tunjangan Operasional & Jabatan, Insentif: rupiah ≥ 0; kosong = 0.',
            '- Diberi Uang Makan: Ya / Tidak. Kalau Ya, Uang Makan per Hari WAJIB diisi lebih dari 0. Kalau Tidak, uang makan disimpan 0.',
            '- Kelompok Daftar Upah: pilih dari daftar, boleh dikosongkan.',
            '',
            'REKENING & COST CENTER',
            '- No. Rekening: angka saja (tanpa spasi atau tanda baca).',
            '- Kode Cost Center: pilih dari daftar.',
            '',
            'BERLAKU MULAI & PERUBAHAN',
            '- Berlaku Mulai sudah diisi tanggal 1 bulan periode template. Ubah kalau perubahan berlaku dari tanggal lain (format DD/MM/YYYY).',
            '- Baris dianggap BERUBAH kalau isinya berbeda dengan data yang berlaku pada tanggal Berlaku Mulai. Hanya data yang berubah yang dicatat sebagai riwayat baru.',
            '- Data lama tidak pernah dihapus. Mengosongkan kolom tidak menghapus data yang sudah ada.',
            '- File hanya disimpan kalau TIDAK ADA baris yang salah. Periksa hasil Pratinjau terlebih dahulu.',
        ];

        foreach ($lines as $index => $line) {
            $sheet->setCellValue('A'.($index + 1), $line);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        foreach (['A9', 'A18', 'A22'] as $cell) {
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }
    }

    /** Sheet diproteksi tanpa sandi: sel terkunci tidak bisa diubah; filter, urut, & lebar kolom tetap boleh. */
    private function protect(Worksheet $sheet): void
    {
        $sheet->getProtection()
            ->setSheet(true)
            ->setAutoFilter(false)
            ->setSort(false)
            ->setFormatColumns(false)
            ->setSelectLockedCells(false)
            ->setSelectUnlockedCells(false);
    }
}
