<?php

namespace App\Services\Payroll;

use App\Support\DocumentStyle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Font as SharedFont;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Menulis FILE EXCEL SPKL dari isi SpklDocument: sheet pertama REKAP (PayrollExportWorkbook::writeTableSheet), lalu
 * satu sheet per kelompok, blok karyawan ditumpuk ke bawah dengan pemisah halaman di antara blok. Tata letak satu blok
 * mengikuti "DAFTAR LEMBUR" GUNUNG MAS:
 *
 *   kop + judul + bulan, identitas (No, Nama, Golongan, Bagian) + Gaji Pokok & tarif per jam,
 *   judul kolom 2 baris, setiap tanggal bulan itu, baris Jumlah / Pengali / Total Jam, baris
 *   "Jumlah Jam yang Dibayar" (A–D) | ":" (E) | "X x Rp. tarif" (F–H) | "= Rp. upah" (I–J), lalu blok tanda tangan.
 *   Label baris ringkasan di A–D, tanda ":" di kolom E (rata tengah) supaya titik duanya sejajar.
 *
 * Kolom: A Hari · B Tanggal · C Dari · D Sampai · E Jumlah · F Jam Pertama · G Setelah Jam Pertama ·
 *        H–J Minggu/Libur (7 Jam Pertama, Setelah 8 Jam Pertama, Setelah 9 Jam Kedua) · K–N Uraian Pekerjaan ·
 *        O Jenis (Reguler / DW, hanya SPKL Lembur Reguler + DW)
 * Angka jam 0 tampil "-" (format angka), tanggal tanpa lembur dibiarkan kosong.
 *
 * CETAK A4 TEGAK: lebar kolom tetap & ramping (judul kolom & Uraian Pekerjaan berlipat), tinggi setiap baris diisi sesuai
 * jumlah baris tulisannya, lalu skala cetak dihitung dari blok karyawan terbesar (fitBlocksToA4) supaya SATU karyawan =
 * SATU halaman. Tidak memakai "Fit To", karena Excel mengabaikan pemisah halaman manual selama "Fit To" aktif.
 */
class SpklWorkbook
{
    /**
     * Lebar kolom (satuan Excel) untuk A4 tegak, total ± 100: kolom jam F–J cukup untuk kata terpanjang judulnya
     * ("Pertama", judul berlipat, huruf SUB_TITLE_FONT_SIZE), Uraian Pekerjaan (K–N, 34) cukup 2 baris untuk uraian
     * ± 50 huruf.
     */
    private const WIDTHS = [
        'A' => 6.5, 'B' => 8.5, 'C' => 6, 'D' => 6, 'E' => 6.5, 'F' => 7, 'G' => 7,
        'H' => 7, 'I' => 7, 'J' => 7, 'K' => 8.5, 'L' => 8.5, 'M' => 8.5, 'N' => 8.5,
    ];

    /** Ukuran huruf judul sub-kolom jam (Dari … Setelah 9 Jam Kedua), supaya kolom F–J bisa ramping. */
    private const SUB_TITLE_FONT_SIZE = 9;

    /** Lebar satu satuan kolom Excel dengan huruf bawaan Tahoma 11, point (diukur di Excel: kolom 10 = ± 60 pt). */
    private const POINTS_PER_WIDTH_UNIT = 6.0;

    /** Kolom Jenis (SPKL Lembur Reguler + DW). */
    private const TYPE_COLUMN = 'O';

    private const TYPE_COLUMN_WIDTH = 7.5;

    /** Kertas A4 (point) & margin cetak (inci). */
    private const A4_WIDTH_POINTS = 595.28;

    private const A4_HEIGHT_POINTS = 841.89;

    private const MARGIN_INCHES = 0.4;

    /** Cadangan perbedaan hitungan lebar/tinggi di sini dengan tampilan Excel (skala dikali ini). */
    private const PRINT_SAFETY = 0.96;

    /** Lebar rata-rata satu huruf Tahoma 11 terhadap satuan lebar kolom Excel (tulisan biasa, bukan angka). */
    private const CHAR_FACTOR = 1.05;

    /** Tinggi baris kosong (jarak antar bagian & ruang tanda tangan), point. */
    private const EMPTY_ROW_HEIGHT = 9;

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

        $rekap = $spreadsheet->createSheet();
        $rekap->setTitle($document['excel_rekap']['title']);
        (new PayrollExportWorkbook)->writeTableSheet($rekap, $document['excel_rekap'], $document);

        $widths = self::widths($document);
        foreach ($document['groups'] as $group) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($group['title']);
            foreach ($widths as $letter => $width) {
                $sheet->getColumnDimension($letter)->setWidth($width);
            }

            $row = 1;
            $blockRanges = [];
            $titleRows = [];
            foreach ($group['blocks'] as $index => $block) {
                $titleRows[] = $row;
                $lastRow = $this->writeBlock($sheet, $block, $document, $row);
                $blockRanges[] = [$row, $lastRow];
                if ($index < count($group['blocks']) - 1) {
                    $sheet->setBreak("A{$lastRow}", Worksheet::BREAK_ROW);   // karyawan berikutnya di halaman baru
                }
                $row = $lastRow + 2;
            }
            $this->centerTitles($sheet, $titleRows, array_keys($widths));
            $this->fitBlocksToA4($sheet, $blockRanges, array_keys($widths));
        }
        $spreadsheet->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'spkl-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * Cetak A4 tegak, satu blok karyawan per halaman:
     *   1. tinggi setiap baris blok = jumlah baris tulisan terbanyak di baris itu × tinggi baris bawaan (judul kolom &
     *      Uraian Pekerjaan berlipat; sel gabungan tidak pernah menyesuaikan tinggi sendiri di Excel); baris kosong
     *      (jarak & ruang tanda tangan) EMPTY_ROW_HEIGHT;
     *   2. skala cetak (persen) = yang terkecil dari (lebar kertas ÷ lebar blok) dan (tinggi kertas ÷ tinggi blok
     *      tertinggi, termasuk baris kosong pemisah di atasnya), dibulatkan ke bawah, maksimal 100%.
     *
     * @param  list<array{int, int}>  $blockRanges  [baris awal, baris akhir] tiap blok
     * @param  list<string>  $letters  kolom blok
     */
    private function fitBlocksToA4(Worksheet $sheet, array $blockRanges, array $letters): void
    {
        $font = $sheet->getParent()->getDefaultStyle()->getFont();
        $lineHeight = SharedFont::getDefaultRowHeightByFont($font);
        $mergedWidths = $this->mergedWidths($sheet);

        $tallest = 0;
        foreach ($blockRanges as $index => [$from, $to]) {
            if ($index > 0) {
                $sheet->getRowDimension($from - 1)->setRowHeight(self::EMPTY_ROW_HEIGHT);   // pemisah di atas blok (halaman baru)
            }
            $height = $index > 0 ? self::EMPTY_ROW_HEIGHT : 0;
            for ($row = $from; $row <= $to; $row++) {
                $lines = $this->lineCount($sheet, $row, $letters, $mergedWidths);
                $rowHeight = $lines === 0 ? self::EMPTY_ROW_HEIGHT : $lines * $lineHeight;
                $sheet->getRowDimension($row)->setRowHeight($rowHeight);
                $height += $rowHeight;
            }
            $tallest = max($tallest, $height);
        }

        $widthPoints = array_sum(array_map(fn (string $letter) => $sheet->getColumnDimension($letter)->getWidth(), $letters)) * self::POINTS_PER_WIDTH_UNIT;
        $margin = self::MARGIN_INCHES * 72;
        $fit = min((self::A4_WIDTH_POINTS - 2 * $margin) / $widthPoints, (self::A4_HEIGHT_POINTS - 2 * $margin) / max($tallest, 1));

        $setup = $sheet->getPageSetup();
        $setup->setOrientation(PageSetup::ORIENTATION_PORTRAIT)->setPaperSize(PageSetup::PAPERSIZE_A4);
        $setup->setScale(max(10, min(100, (int) floor($fit * self::PRINT_SAFETY * 100))));   // juga mematikan "Fit To"
        $setup->setHorizontalCentered(true);   // isi di tengah kertas, jadi judul yang ditengahkan juga di tengah kertas
        $setup->setPrintArea('A1:'.end($letters).$blockRanges[array_key_last($blockRanges)][1]);
        $sheet->getPageMargins()->setLeft(self::MARGIN_INCHES)->setRight(self::MARGIN_INCHES)
            ->setTop(self::MARGIN_INCHES)->setBottom(self::MARGIN_INCHES);
    }

    /**
     * Jumlah baris tulisan terbanyak di satu baris sheet: sel berlipat (wrap) dipecah per kata selebar kolomnya
     * (atau gabungan kolomnya; kata tidak dipotong, sama dengan Excel); sel lain satu baris. 0 = baris kosong.
     *
     * @param  array<string, float>  $mergedWidths  [sel kiri atas gabungan => total lebar]
     */
    private function lineCount(Worksheet $sheet, int $row, array $letters, array $mergedWidths): int
    {
        $lines = 0;
        foreach ($letters as $letter) {
            $cell = "{$letter}{$row}";
            $value = $sheet->cellExists($cell) ? $sheet->getCell($cell)->getValue() : null;
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_string($value) || ! $sheet->getStyle($cell)->getAlignment()->getWrapText()) {
                $lines = max($lines, 1);

                continue;
            }
            $width = $mergedWidths[$cell] ?? $sheet->getColumnDimension($letter)->getWidth();
            $perLine = max(1, (int) floor(($width - 1) / self::CHAR_FACTOR));
            $lines = max($lines, count(explode("\n", wordwrap($value, $perLine, "\n"))));
        }

        return $lines;
    }

    /** @return array<string, float> [sel kiri atas setiap gabungan sel => total lebar kolomnya] */
    private function mergedWidths(Worksheet $sheet): array
    {
        $widths = [];
        foreach ($sheet->getMergeCells() as $range) {
            [[$fromColumn, $fromRow], [$toColumn]] = Coordinate::rangeBoundaries($range);
            $total = 0;
            for ($column = $fromColumn; $column <= $toColumn; $column++) {
                $total += $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->getWidth();
            }
            $widths[Coordinate::stringFromColumnIndex($fromColumn).$fromRow] = $total;
        }

        return $widths;
    }

    /** @return array<string, int> [huruf kolom => lebar], ditambah kolom Jenis untuk SPKL Lembur Reguler + DW */
    private static function widths(array $document): array
    {
        return $document['show_type'] ? [...self::WIDTHS, self::TYPE_COLUMN => self::TYPE_COLUMN_WIDTH] : self::WIDTHS;
    }

    /**
     * Pindahkan judul "DAFTAR LEMBUR" ke gabungan kolom yang lebar kiri & kanannya SAMA (setelah lebar kolom final),
     * supaya judul tepat di tengah kertas. Sisi kiri harus cukup untuk nama perusahaan, sisi kanan untuk tulisan bulan,
     * dan dari pilihan yang memenuhi dipilih yang selisih kiri-kanannya paling kecil; sisa selisihnya ditutup dengan
     * melebarkan satu kolom di sisi yang lebih sempit.
     *
     * @param  list<int>  $titleRows
     * @param  list<string>  $letters  semua kolom blok
     */
    private function centerTitles(Worksheet $sheet, array $titleRows, array $letters): void
    {
        $lastLetter = end($letters);
        $widths = array_map(fn (string $letter) => $sheet->getColumnDimension($letter)->getWidth(), $letters);
        $count = count($letters);
        // Lebar minimal (satuan kolom Excel) tiap sisi: teks perusahaan (kiri) & bulan (kanan), huruf tebal.
        $firstRow = $titleRows[0] ?? 1;
        $leftMinimum = mb_strlen((string) $sheet->getCell("A{$firstRow}")->getValue()) * 1.25;
        $rightMinimum = mb_strlen((string) $sheet->getCell("{$lastLetter}{$firstRow}")->getValue()) * 1.25;
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
     * Tulis satu blok mulai baris $top. Hasilnya baris terakhir blok (nama penanda tangan).
     */
    private function writeBlock(Worksheet $sheet, array $block, array $document, int $top): int
    {
        $letters = array_keys(self::widths($document));
        $last = end($letters);
        $showType = $document['show_type'];

        // Kop & identitas.
        // Kop: perusahaan (singkat, meluber ke kanan) | judul (ditengahkan sesudah lebar kolom final, lihat centerTitles) |
        // bulan (rata kanan di kolom terakhir, meluber ke kiri).
        $sheet->setCellValue("A{$top}", DocumentStyle::COMPANY_SHORT);
        $sheet->setCellValue("F{$top}", $document['title']);
        $sheet->setCellValue("{$last}{$top}", 'BULAN : '.mb_strtoupper($document['month_label']).($document['is_draft'] ? ' (DRAF)' : ''));
        $sheet->getStyle("A{$top}:{$last}{$top}")->getFont()->setBold(true);
        $sheet->getStyle("{$last}{$top}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $identity = [['No', $block['no']], ['Nama', $block['name']], ['Golongan', $block['grade']], ['Bagian', $block['section']]];
        foreach ($identity as $offset => [$label, $value]) {
            $sheet->setCellValue('A'.($top + 2 + $offset), $label);
            PayrollExportWorkbook::setValue($sheet, 'B'.($top + 2 + $offset), ': '.$value);
        }
        // Label di I (I–L cukup lebar untuk satu baris), nilai di M.
        $sheet->setCellValue('I'.($top + 3), 'Gaji Pokok 1 (satu) bulan');
        $sheet->setCellValue('M'.($top + 3), ': Rp '.PayrollExportText::rupiah($block['base_salary']));
        $sheet->setCellValue('I'.($top + 4), 'Tarif per jam');
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
        if ($showType) {
            $sheet->setCellValue(self::TYPE_COLUMN.$head, 'Jenis');
            $sheet->mergeCells(self::TYPE_COLUMN.$head.':'.self::TYPE_COLUMN.$sub);
        }
        $subTitles = [
            'C' => 'Dari', 'D' => 'Sampai', 'E' => 'Jumlah', 'F' => 'Jam Pertama', 'G' => 'Setelah Jam Pertama',
            'H' => '7 Jam Pertama', 'I' => 'Setelah 8 Jam Pertama', 'J' => 'Setelah 9 Jam Kedua',
        ];
        foreach ($subTitles as $letter => $title) {
            $sheet->setCellValue("{$letter}{$sub}", $title);
        }
        $sheet->getStyle("C{$sub}:J{$sub}")->getFont()->setSize(self::SUB_TITLE_FONT_SIZE);
        $headStyle = $sheet->getStyle("A{$head}:{$last}{$sub}");
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
            if ($showType) {
                PayrollExportWorkbook::setValue($sheet, self::TYPE_COLUMN.$row, $day['type']);
            }
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

        $sheet->getStyle("A{$head}:{$last}{$paidRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A{$sumRow}:{$last}{$paidRow}")->getFont()->setBold(true);
        $sheet->getStyle('B'.($sub + 1).":J{$paidRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        if ($showType) {
            $sheet->getStyle(self::TYPE_COLUMN.($sub + 1).':'.self::TYPE_COLUMN.$paidRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $sheet->getStyle("A{$sumRow}:A{$paidRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('E'.($sub + 1).":J{$productRow}")->getNumberFormat()->setFormatCode(self::HOURS_FORMAT);
        $sheet->getStyle('M'.($top + 3).':M'.($top + 4))->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);

        // Uraian Pekerjaan & jam Dari/Sampai (beberapa lembur satu tanggal: "17:00, 20:00") berlipat.
        $sheet->getStyle('K'.($sub + 1).":K{$paidRow}")->getAlignment()->setWrapText(true);
        $sheet->getStyle('C'.($sub + 1).':D'.($sumRow - 1))->getAlignment()->setWrapText(true);

        return PayrollExportWorkbook::writeSignatureBlock($sheet, $block['signature'], $paidRow + 1, count($letters));
    }
}
