<?php

namespace App\Services\Payroll;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Menulis FILE EXCEL SPKL dari isi SpklDocument: satu sheet per kelompok, blok karyawan ditumpuk ke bawah
 * dengan pemisah halaman di antara blok. Tata letak satu blok mengikuti "DAFTAR LEMBUR" GUNUNG MAS:
 *
 *   kop + judul + bulan, identitas (No, Nama, Golongan, Bagian) + Gaji Pokok & tarif per jam,
 *   judul kolom 2 baris, setiap tanggal bulan itu, Jumlah / kali / hasil kali, jam dibayar × tarif = upah,
 *   lalu blok tanda tangan.
 *
 * Kolom: A Hari · B Tanggal · C Dari · D Sampai · E Jumlah · F Jam Pertama · G Setelah Jam Pertama ·
 *        H Minggu/Libur 7 Jam Pertama · I–L Uraian Pekerjaan
 */
class SpklWorkbook
{
    private const FONT = 'Arial';

    private const COLUMN_COUNT = 12;

    private const WIDTHS = ['A' => 9, 'B' => 8, 'C' => 8, 'D' => 8, 'E' => 8, 'F' => 9, 'G' => 10, 'H' => 10, 'I' => 12, 'J' => 12, 'K' => 12, 'L' => 12];

    private const MONEY_FORMAT = '#,##0';

    /** @param  array<string, mixed>  $document  hasil SpklDocument::build */
    public function write(array $document): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName(self::FONT)->setSize(9);
        $spreadsheet->removeSheetByIndex(0);

        foreach ($document['groups'] as $group) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($group['title']);
            foreach (self::WIDTHS as $letter => $width) {
                $sheet->getColumnDimension($letter)->setWidth($width);
            }

            $row = 1;
            foreach ($group['blocks'] as $index => $block) {
                $lastRow = $this->writeBlock($sheet, $block, $document, $row);
                if ($index < count($group['blocks']) - 1) {
                    $sheet->setBreak("A{$lastRow}", Worksheet::BREAK_ROW);
                }
                $row = $lastRow + 2;
            }

            $setup = $sheet->getPageSetup();
            $setup->setOrientation(PageSetup::ORIENTATION_PORTRAIT)->setPaperSize(PageSetup::PAPERSIZE_A4);
            $setup->setFitToWidth(1)->setFitToHeight(0);
            $sheet->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.4)->setBottom(0.4);
        }
        $spreadsheet->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'spkl-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /** Tulis satu blok mulai baris $top; mengembalikan baris terakhir blok. */
    private function writeBlock(Worksheet $sheet, array $block, array $document, int $top): int
    {
        // Kop & identitas.
        $sheet->setCellValue("A{$top}", 'PTP NUSANTARA I REGIONAL 2');
        $sheet->setCellValue("E{$top}", $document['title']);
        $sheet->mergeCells("E{$top}:H{$top}");
        $sheet->setCellValue("J{$top}", 'BULAN : '.mb_strtoupper($document['month_label']).($document['is_draft'] ? ' (DRAF)' : ''));
        $sheet->getStyle("A{$top}:L{$top}")->getFont()->setBold(true);
        $sheet->getStyle("E{$top}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $identity = [['No', $block['no']], ['Nama', $block['name']], ['Golongan', $block['grade']], ['Bagian', $block['section']]];
        foreach ($identity as $offset => [$label, $value]) {
            $sheet->setCellValue('A'.($top + 1 + $offset), $label);
            PayrollExportWorkbook::setValue($sheet, 'B'.($top + 1 + $offset), ': '.$value);
        }
        $sheet->setCellValue('I'.($top + 2), 'Gaji Pokok 1 (satu) bulan');
        $sheet->setCellValue('K'.($top + 2), ': Rp '.PayrollExportText::rupiah($block['base_salary']));
        $sheet->setCellValue('I'.($top + 3), 'Tarif per jam');
        $sheet->setCellValue('K'.($top + 3), ': Rp '.PayrollExportText::rupiah($block['hourly_rate']));

        // Judul kolom.
        $head = $top + 6;
        $sub = $head + 1;
        foreach (['A' => 'Hari', 'B' => 'Tanggal'] as $letter => $title) {
            $sheet->setCellValue("{$letter}{$head}", $title);
            $sheet->mergeCells("{$letter}{$head}:{$letter}{$sub}");
        }
        $sheet->setCellValue("C{$head}", 'Jumlah Jam');
        $sheet->mergeCells("C{$head}:E{$head}");
        $sheet->setCellValue("F{$head}", 'Hari-Hari Biasa');
        $sheet->mergeCells("F{$head}:G{$head}");
        $sheet->setCellValue("H{$head}", 'Minggu/Libur');
        $sheet->setCellValue("I{$head}", 'Uraian Pekerjaan');
        $sheet->mergeCells("I{$head}:L{$sub}");
        foreach (['C' => 'Dari', 'D' => 'Sampai', 'E' => 'Jumlah', 'F' => 'Jam Pertama', 'G' => 'Setelah Jam Pertama', 'H' => '7 Jam Pertama'] as $letter => $title) {
            $sheet->setCellValue("{$letter}{$sub}", $title);
        }
        $headStyle = $sheet->getStyle("A{$head}:L{$sub}");
        $headStyle->getFont()->setBold(true);
        $headStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $headStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9E1F2');

        // Setiap tanggal.
        $row = $sub + 1;
        foreach ($block['days'] as $day) {
            $sheet->setCellValue("A{$row}", $day['day_name']);
            $sheet->setCellValue("B{$row}", $day['date']);
            PayrollExportWorkbook::setValue($sheet, "C{$row}", $day['from'] ?: null);
            PayrollExportWorkbook::setValue($sheet, "D{$row}", $day['to'] ?: null);
            PayrollExportWorkbook::setValue($sheet, "E{$row}", $day['hours']);
            PayrollExportWorkbook::setValue($sheet, "F{$row}", $day['first']);
            PayrollExportWorkbook::setValue($sheet, "G{$row}", $day['next']);
            PayrollExportWorkbook::setValue($sheet, "H{$row}", $day['holiday']);
            PayrollExportWorkbook::setValue($sheet, "I{$row}", $day['description'] ?: null);
            $sheet->mergeCells("I{$row}:L{$row}");
            $row++;
        }

        // Jumlah, kali, hasil kali.
        $sumRow = $row;
        $sheet->setCellValue("A{$sumRow}", 'Jumlah :');
        $sheet->mergeCells("A{$sumRow}:D{$sumRow}");
        $sheet->setCellValue("E{$sumRow}", $block['totals']['hours']);
        $sheet->setCellValue("F{$sumRow}", $block['totals']['first']);
        $sheet->setCellValue("G{$sumRow}", $block['totals']['next']);
        $sheet->setCellValue("H{$sumRow}", 0);

        $timesRow = $sumRow + 1;
        $sheet->setCellValue("A{$timesRow}", 'kali');
        $sheet->mergeCells("A{$timesRow}:E{$timesRow}");
        $sheet->setCellValue("F{$timesRow}", $block['multipliers']['first']);
        $sheet->setCellValue("G{$timesRow}", $block['multipliers']['next']);
        $sheet->setCellValue("H{$timesRow}", '-');

        $productRow = $timesRow + 1;
        $sheet->mergeCells("A{$productRow}:E{$productRow}");
        $sheet->setCellValue("F{$productRow}", $block['products']['first']);
        $sheet->setCellValue("G{$productRow}", $block['products']['next']);
        $sheet->setCellValue("H{$productRow}", 0);

        $sheet->getStyle("A{$head}:L{$productRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A{$sumRow}:L{$productRow}")->getFont()->setBold(true);
        $sheet->getStyle('B'.($sub + 1).":H{$productRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $paidRow = $productRow + 1;
        $sheet->setCellValue("A{$paidRow}", 'Jumlah jam yang dibayar : '.SpklDocument::hours($block['paid_hours'])
            .' x Rp. '.PayrollExportText::rupiah($block['hourly_rate']).' = Rp '.PayrollExportText::rupiah($block['pay']));
        $sheet->mergeCells("A{$paidRow}:L{$paidRow}");
        $sheet->getStyle("A{$paidRow}")->getFont()->setBold(true);
        $sheet->getStyle('K'.($top + 2).':K'.($top + 3))->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);

        return PayrollExportWorkbook::writeSignatureBlock($sheet, $block['signature'], $paidRow + 1, self::COLUMN_COUNT);
    }
}
