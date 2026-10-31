<?php

namespace App\Services\Payroll;

use App\Support\DocumentStyle;
use App\Support\ExcelColumnFit;
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
 *   judul kolom 2 baris, setiap tanggal bulan itu, baris Jumlah / Pengali / Total Jam, baris
 *   "Jumlah Jam yang Dibayar" (A–D) | ":" (E) | "X x Rp. tarif" (F–H) | "= Rp. upah" (I–J), lalu blok tanda tangan.
 *   Label baris ringkasan di A–D, tanda ":" di kolom E (rata tengah) supaya titik duanya sejajar.
 *
 * Kolom: A Hari · B Tanggal · C Dari · D Sampai · E Jumlah · F Jam Pertama · G Setelah Jam Pertama ·
 *        H–J Minggu/Libur (7 Jam Pertama, Setelah 8 Jam Pertama, Setelah 9 Jam Kedua) · K–N Uraian Pekerjaan
 * Angka jam 0 tampil "-" (format angka), tanggal tanpa lembur dibiarkan kosong.
 */
class SpklWorkbook
{
    private const COLUMN_COUNT = 14;

    private const WIDTHS = [
        'A' => 10, 'B' => 12, 'C' => 8, 'D' => 8, 'E' => 8, 'F' => 9, 'G' => 11,
        'H' => 9, 'I' => 11, 'J' => 11, 'K' => 11, 'L' => 11, 'M' => 11, 'N' => 11,
    ];

    /** Kolom Minggu/Libur: [huruf => kunci data]. */
    private const HOLIDAY_COLUMNS = ['H' => 'holiday_7', 'I' => 'holiday_8', 'J' => 'holiday_9'];

    /** Angka jam: 0 tampil "-". */
    private const HOURS_FORMAT = 'General;-General;"-"';

    private const MONEY_FORMAT = '#,##0';

    /** @param  array<string, mixed>  $document  hasil SpklDocument::build */
    public function write(array $document): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName(DocumentStyle::FONT)->setSize(DocumentStyle::FONT_SIZE);
        $spreadsheet->removeSheetByIndex(0);

        foreach ($document['groups'] as $group) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($group['title']);
            foreach (self::WIDTHS as $letter => $width) {
                $sheet->getColumnDimension($letter)->setWidth($width);
            }

            $row = 1;
            $tableRanges = [];
            $titleRows = [];
            foreach ($group['blocks'] as $index => $block) {
                $titleRows[] = $row;
                [$lastRow, $tableRanges[]] = $this->writeBlock($sheet, $block, $document, $row);
                if ($index < count($group['blocks']) - 1) {
                    $sheet->setBreak("A{$lastRow}", Worksheet::BREAK_ROW);
                }
                $row = $lastRow + 2;
            }
            // Lebar kolom mengikuti tulisan terpanjang di tabel (satu baris); Uraian (K–N) berlipat.
            ExcelColumnFit::applyToRanges($sheet, $tableRanges);
            $this->centerTitles($sheet, $titleRows);

            // Dicetak diperkecil: satu blok karyawan = satu halaman A4.
            $setup = $sheet->getPageSetup();
            $setup->setOrientation(PageSetup::ORIENTATION_PORTRAIT)->setPaperSize(PageSetup::PAPERSIZE_A4);
            $setup->setFitToWidth(1)->setFitToHeight(count($group['blocks']));
            $setup->setHorizontalCentered(true);   // isi di tengah kertas, jadi judul yang ditengahkan juga di tengah kertas
            $sheet->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.4)->setBottom(0.4);
        }
        $spreadsheet->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'spkl-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * Pindahkan judul "DAFTAR LEMBUR" ke gabungan kolom yang lebar kiri & kanannya SAMA (setelah lebar kolom final),
     * supaya judul tepat di tengah kertas. Sisi kiri harus cukup untuk nama perusahaan, sisi kanan untuk tulisan bulan,
     * dan dari pilihan yang memenuhi dipilih yang selisih kiri-kanannya paling kecil; sisa selisihnya ditutup dengan
     * melebarkan satu kolom di sisi yang lebih sempit.
     *
     * @param  list<int>  $titleRows
     */
    private function centerTitles(Worksheet $sheet, array $titleRows): void
    {
        $letters = array_keys(self::WIDTHS);
        $widths = array_map(fn (string $letter) => $sheet->getColumnDimension($letter)->getWidth(), $letters);
        $count = count($letters);
        // Lebar minimal (satuan kolom Excel) tiap sisi: teks perusahaan (kiri) & bulan (kanan), huruf tebal.
        $firstRow = $titleRows[0] ?? 1;
        $leftMinimum = mb_strlen((string) $sheet->getCell("A{$firstRow}")->getValue()) * 1.25;
        $rightMinimum = mb_strlen((string) $sheet->getCell("N{$firstRow}")->getValue()) * 1.25;
        $best = [5, 9];   // F–J kalau tidak ada yang memenuhi
        $bestGap = INF;

        for ($from = 1; $from < $count - 1; $from++) {
            for ($to = $from; $to < $count - 1; $to++) {
                $left = array_sum(array_slice($widths, 0, $from));
                $right = array_sum(array_slice($widths, $to + 1));
                $titleWidth = array_sum(array_slice($widths, $from, $to - $from + 1));
                if ($titleWidth < 18 || $left < $leftMinimum || $right < $rightMinimum) {   // semua tulisan harus muat
                    continue;
                }
                if (abs($left - $right) < $bestGap) {
                    [$best, $bestGap] = [[$from, $to], abs($left - $right)];
                }
            }
        }

        // Sisa selisih ditutup dengan melebarkan kolom di sisi yang lebih sempit (bersebelahan dengan judul).
        $gap = array_sum(array_slice($widths, 0, $best[0])) - array_sum(array_slice($widths, $best[1] + 1));
        $widenIndex = $gap > 0 ? $best[1] + 1 : $best[0] - 1;
        if ($bestGap !== INF && $gap != 0) {
            $sheet->getColumnDimension($letters[$widenIndex])->setWidth($widths[$widenIndex] + abs($gap));
        }

        [$from, $to] = [$letters[$best[0]], $letters[$best[1]]];
        foreach ($titleRows as $row) {
            $title = $sheet->getCell("F{$row}")->getValue();
            $sheet->setCellValue("F{$row}", null);
            $sheet->setCellValue("{$from}{$row}", $title);
            $sheet->mergeCells("{$from}{$row}:{$to}{$row}");
            $sheet->getStyle("{$from}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
    }

    /**
     * Tulis satu blok mulai baris $top.
     *
     * @return array{int, array{int, int}} [baris terakhir blok, [baris judul tabel, baris "Jumlah Jam yang Dibayar"]]
     */
    private function writeBlock(Worksheet $sheet, array $block, array $document, int $top): array
    {
        // Kop & identitas.
        // Kop: perusahaan (singkat, meluber ke kanan) | judul (ditengahkan sesudah lebar kolom final, lihat centerTitles) |
        // bulan (rata kanan di kolom terakhir, meluber ke kiri).
        $sheet->setCellValue("A{$top}", DocumentStyle::COMPANY_SHORT);
        $sheet->setCellValue("F{$top}", $document['title']);
        $sheet->setCellValue("N{$top}", 'BULAN : '.mb_strtoupper($document['month_label']).($document['is_draft'] ? ' (DRAF)' : ''));
        $sheet->getStyle("A{$top}:N{$top}")->getFont()->setBold(true);
        $sheet->getStyle("N{$top}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $identity = [['No', $block['no']], ['Nama', $block['name']], ['Golongan', $block['grade']], ['Bagian', $block['section']]];
        foreach ($identity as $offset => [$label, $value]) {
            $sheet->setCellValue('A'.($top + 2 + $offset), $label);
            PayrollExportWorkbook::setValue($sheet, 'B'.($top + 2 + $offset), ': '.$value);
        }
        $sheet->setCellValue('J'.($top + 3), 'Gaji Pokok 1 (satu) bulan');
        $sheet->setCellValue('M'.($top + 3), ': Rp '.PayrollExportText::rupiah($block['base_salary']));
        $sheet->setCellValue('J'.($top + 4), 'Tarif per jam');
        $sheet->setCellValue('M'.($top + 4), ': Rp '.PayrollExportText::rupiah($block['hourly_rate']));

        // Judul kolom.
        $head = $top + 7;
        $sub = $head + 1;
        foreach (['A' => 'Hari', 'B' => 'Tanggal'] as $letter => $title) {
            $sheet->setCellValue("{$letter}{$head}", $title);
            $sheet->mergeCells("{$letter}{$head}:{$letter}{$sub}");
        }
        foreach ([['C', 'E', 'Jumlah Jam'], ['F', 'G', 'Hari-Hari Biasa'], ['H', 'J', 'Minggu/Libur']] as [$from, $to, $title]) {
            $sheet->setCellValue("{$from}{$head}", $title);
            $sheet->mergeCells("{$from}{$head}:{$to}{$head}");
        }
        $sheet->setCellValue("K{$head}", 'Uraian Pekerjaan');
        $sheet->mergeCells("K{$head}:N{$sub}");
        $subTitles = [
            'C' => 'Dari', 'D' => 'Sampai', 'E' => 'Jumlah', 'F' => 'Jam Pertama', 'G' => 'Setelah Jam Pertama',
            'H' => '7 Jam Pertama', 'I' => 'Setelah 8 Jam Pertama', 'J' => 'Setelah 9 Jam Kedua',
        ];
        foreach ($subTitles as $letter => $title) {
            $sheet->setCellValue("{$letter}{$sub}", $title);
        }
        $headStyle = $sheet->getStyle("A{$head}:N{$sub}");
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
            foreach (self::HOLIDAY_COLUMNS as $letter => $key) {
                PayrollExportWorkbook::setValue($sheet, "{$letter}{$row}", $day[$key]);
            }
            PayrollExportWorkbook::setValue($sheet, "K{$row}", $day['description'] ?: null);
            $sheet->mergeCells("K{$row}:N{$row}");
            $row++;
        }

        // Jumlah (tanpa total jam kolom E), Pengali, Total Jam.
        $sumRow = $row;
        $timesRow = $sumRow + 1;
        $productRow = $timesRow + 1;
        $summary = [
            $sumRow => ['Jumlah', $block['totals']],
            $timesRow => ['Pengali', $block['multipliers']],
            $productRow => ['Total Jam', $block['products']],
        ];
        foreach ($summary as $summaryRow => [$label, $values]) {
            $sheet->setCellValue("A{$summaryRow}", $label);
            $sheet->mergeCells("A{$summaryRow}:D{$summaryRow}");
            $sheet->setCellValue("E{$summaryRow}", ':');
            $sheet->setCellValue("F{$summaryRow}", $values['first']);
            $sheet->setCellValue("G{$summaryRow}", $values['next']);
            foreach (self::HOLIDAY_COLUMNS as $letter => $key) {
                $sheet->setCellValue("{$letter}{$summaryRow}", $values[$key] ?? 0);   // pengali null = "-"
            }
            $sheet->mergeCells("K{$summaryRow}:N{$summaryRow}");
        }

        // Jumlah Jam yang Dibayar : | X x Rp. tarif | = Rp. upah — satu kesatuan dengan tabel.
        $paidRow = $productRow + 1;
        $sheet->setCellValue("A{$paidRow}", 'Jumlah Jam yang Dibayar');
        $sheet->mergeCells("A{$paidRow}:D{$paidRow}");
        $sheet->setCellValue("E{$paidRow}", ':');
        $sheet->setCellValue("F{$paidRow}", SpklDocument::hours($block['paid_hours']).' x Rp. '.PayrollExportText::rupiah($block['hourly_rate']));
        $sheet->mergeCells("F{$paidRow}:H{$paidRow}");
        PayrollExportWorkbook::setValue($sheet, "I{$paidRow}", '= Rp. '.PayrollExportText::rupiah($block['pay']));   // teks, bukan rumus
        $sheet->mergeCells("I{$paidRow}:J{$paidRow}");
        $sheet->mergeCells("K{$paidRow}:N{$paidRow}");

        $sheet->getStyle("A{$head}:N{$paidRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A{$sumRow}:N{$paidRow}")->getFont()->setBold(true);
        $sheet->getStyle('B'.($sub + 1).":J{$paidRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A{$sumRow}:A{$paidRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('E'.($sub + 1).":J{$productRow}")->getNumberFormat()->setFormatCode(self::HOURS_FORMAT);
        $sheet->getStyle('M'.($top + 3).':M'.($top + 4))->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);

        $sheet->getStyle('K'.($sub + 1).":K{$paidRow}")->getAlignment()->setWrapText(true);

        return [PayrollExportWorkbook::writeSignatureBlock($sheet, $block['signature'], $paidRow + 1, self::COLUMN_COUNT), [$head, $paidRow]];
    }
}
