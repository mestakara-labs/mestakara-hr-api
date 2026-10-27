<?php

namespace App\Services\Employee;

use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Menulis FILE EXCEL ekspor Data Karyawan dengan format "Database Karyawan - Unit Agrowisata":
 *
 *   baris 1–2   header      (Tahoma 11 putih, latar biru / hijau, rata tengah, sel digabung)
 *   baris 3     baris filter (kosong, latar abu-abu; tombol filter Excel ada di baris ini)
 *   baris 4 …   data        (Tahoma 11 hitam, satu baris per karyawan)
 *
 * Kolom & formatnya diambil dari EmployeeExportColumns; isi baris dari EmployeeExportService.
 * Hasilnya file sementara (.xlsx) yang dihapus setelah dikirim ke browser.
 */
class EmployeeExportWorkbook
{
    public const SHEET_TITLE = 'Database Karyawan';

    public const HEADER_BLUE = '215F9A';

    public const HEADER_GREEN = '00B050';

    public const FILTER_GREY = 'D9D9D9';

    private const FONT = 'Tahoma';

    private const FONT_SIZE = 11;

    /** Font bawaan workbook (sel di luar tabel). */
    private const DEFAULT_FONT = 'Aptos Narrow';

    /** Baris tempat tombol filter Excel (di bawah header). */
    private const FILTER_ROW = 3;

    private const FIRST_DATA_ROW = 4;

    private const HEADER_ROW_HEIGHTS = [1 => 30, 2 => 54];

    private const FILTER_ROW_HEIGHT = 20;

    private const DATA_ROW_HEIGHT = 30;

    private const ZOOM = 55;

    /**
     * Tulis file Excel dari baris data, lalu kembalikan path file sementaranya.
     *
     * @param  list<array<string, mixed>>  $rows  [huruf kolom => nilai] per karyawan
     */
    public function write(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName(self::DEFAULT_FONT)->setSize(self::FONT_SIZE);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET_TITLE);

        $columns = EmployeeExportColumns::all();
        $lastColumn = end($columns)['letter'];
        $lastRow = self::FIRST_DATA_ROW + count($rows) - 1;

        $this->writeHeaders($sheet, $columns, $lastColumn);
        $this->styleFilterRow($sheet, $lastColumn);
        $this->writeRows($sheet, $columns, $rows);
        if ($rows !== []) {
            $this->styleRows($sheet, $columns, $lastRow);
        }

        // Freeze di bawah baris filter, tombol filter di baris filter, zoom 55%.
        $sheet->freezePane('A'.self::FIRST_DATA_ROW);
        $sheet->setAutoFilter('A'.self::FILTER_ROW.':'.$lastColumn.max($lastRow, self::FILTER_ROW));
        $sheet->getSheetView()->setZoomScale(self::ZOOM);
        $sheet->setSelectedCell('A'.self::FIRST_DATA_ROW);

        $path = tempnam(sys_get_temp_dir(), 'ekspor-karyawan-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    // -------------------------------------------------------------------------
    // Header (baris 1–2)
    // -------------------------------------------------------------------------

    /** @param  list<array<string, mixed>>  $columns */
    private function writeHeaders(Worksheet $sheet, array $columns, string $lastColumn): void
    {
        foreach ($columns as $column) {
            $sheet->setCellValue($column['letter'].'1', $column['top']);
            $sheet->setCellValue($column['letter'].'2', $column['bottom']);

            $dimension = $sheet->getColumnDimension($column['letter']);
            $dimension->setWidth($column['width']);
            $dimension->setVisible(! $column['hidden']);
        }

        $this->mergeHeaders($sheet, $columns);

        $header = $sheet->getStyle("A1:{$lastColumn}2");
        $header->getFont()->setName(self::FONT)->setSize(self::FONT_SIZE)->setBold(false)->getColor()->setRGB('FFFFFF');
        $header->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $header->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER_BLUE);

        foreach ($columns as $column) {
            if ($column['green']) {
                $sheet->getStyle("{$column['letter']}1:{$column['letter']}2")
                    ->getFill()->getStartColor()->setRGB(self::HEADER_GREEN);
            }
        }

        foreach (self::HEADER_ROW_HEIGHTS as $row => $height) {
            $sheet->getRowDimension($row)->setRowHeight($height);
        }
    }

    /**
     * Gabungkan sel header:
     *   - baris 1 terisi, baris 2 kosong  -> gabung ke bawah, mis. A1:A2
     *   - baris 1 & 2 terisi             -> judul kelompok, gabung ke kanan sampai kolom terakhir
     *                                       yang baris 1-nya kosong, mis. AD1:AE1
     *
     * @param  list<array<string, mixed>>  $columns
     */
    private function mergeHeaders(Worksheet $sheet, array $columns): void
    {
        foreach ($columns as $index => $column) {
            if ($column['top'] === null) {
                continue;
            }

            if ($column['bottom'] === null) {
                $sheet->mergeCells("{$column['letter']}1:{$column['letter']}2");

                continue;
            }

            $groupEnd = $column['letter'];
            for ($next = $index + 1; isset($columns[$next]) && $columns[$next]['top'] === null; $next++) {
                $groupEnd = $columns[$next]['letter'];
            }
            if ($groupEnd !== $column['letter']) {
                $sheet->mergeCells("{$column['letter']}1:{$groupEnd}1");
            }
        }
    }

    // -------------------------------------------------------------------------
    // Baris filter (baris 3)
    // -------------------------------------------------------------------------

    /** Baris kosong berlatar abu-abu tempat tombol filter Excel. */
    private function styleFilterRow(Worksheet $sheet, string $lastColumn): void
    {
        $filterRow = $sheet->getStyle('A'.self::FILTER_ROW.":{$lastColumn}".self::FILTER_ROW);
        $filterRow->getFont()->setName(self::FONT)->setSize(self::FONT_SIZE);
        $filterRow->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $filterRow->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::FILTER_GREY);

        $sheet->getRowDimension(self::FILTER_ROW)->setRowHeight(self::FILTER_ROW_HEIGHT);
    }

    // -------------------------------------------------------------------------
    // Data (baris 4 dst.)
    // -------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeRows(Worksheet $sheet, array $columns, array $rows): void
    {
        foreach ($rows as $index => $row) {
            $rowNumber = self::FIRST_DATA_ROW + $index;

            foreach ($columns as $column) {
                $value = $row[$column['letter']] ?? null;
                $cell = $column['letter'].$rowNumber;

                if ($value === null || $value === '') {
                    continue;
                }

                if ($value instanceof DateTimeInterface) {
                    $sheet->setCellValue($cell, ExcelDate::PHPToExcel($value));
                } elseif (is_string($value)) {
                    // Teks ditulis apa adanya (NIK/KTP tidak berubah menjadi angka).
                    $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($cell, $value);
                }
            }

            $sheet->getRowDimension($rowNumber)->setRowHeight(self::DATA_ROW_HEIGHT);
        }
    }

    /**
     * Style data: Tahoma 11 hitam, rata tengah vertikal, border tipis; rata tengah horizontal & format isi
     * per kolom. Setiap kolom di-style SEKALI dengan style lengkap (jauh lebih cepat daripada berlapis).
     *
     * @param  list<array<string, mixed>>  $columns
     */
    private function styleRows(Worksheet $sheet, array $columns, int $lastRow): void
    {
        foreach ($columns as $column) {
            $range = $column['letter'].self::FIRST_DATA_ROW.":{$column['letter']}{$lastRow}";
            // Kolom tanggal selalu rata tengah.
            $isCentered = $column['center'] || $column['format'] === EmployeeExportColumns::DATE;

            $sheet->getStyle($range)->applyFromArray([
                'font' => ['name' => self::FONT, 'size' => self::FONT_SIZE, 'color' => ['rgb' => '000000']],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'horizontal' => $isCentered ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_GENERAL,
                ],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                'numberFormat' => ['formatCode' => $column['format'] ?? 'General'],
            ]);
        }
    }
}
