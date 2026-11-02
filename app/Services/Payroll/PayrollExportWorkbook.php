<?php

namespace App\Services\Payroll;

use App\Support\DocumentStyle;
use App\Support\ExcelColumnFit;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Menulis FILE EXCEL Daftar Upah dari isi PayrollExportDocument, tata letak mengikuti DU "GUNUNG MAS":
 *
 *   baris 1–2   kop (kiri), judul (tengah), "Bulan : …" / "Hal. : n" (kanan)
 *   baris 3–4   nama kelompok/bidang & sub unit (kode afd)
 *   baris 6–7   judul kolom dua tingkat (HARI KERJA, Tunjangan, Potongan)
 *   baris 8…    per objek penempatan: judul objek, karyawan, JUMLAH objek (abu-abu); lalu JUMLAH TOTAL, lalu
 *               "<tempat>, <tanggal>" & blok tanda tangan
 *
 * Sheet pertama REKAP (kelompok terpilih ditumpuk). Dokumen Payroll: rincian per kelompok berupa tabel satu tingkat
 * (writeTableSheet, juga dipakai REKAP SPKL), juga dipecah per objek penempatan. Rupiah tanpa desimal.
 */
class PayrollExportWorkbook
{
    private const MONEY_FORMAT = '#,##0';

    /** Angka jam: 1,5; 0 tampil "-". */
    private const HOURS_FORMAT = '#,##0.##;-#,##0.##;"-"';

    private const HEADER_FILL = 'D9E1F2';

    /** Latar baris JUMLAH per objek penempatan. */
    private const SECTION_TOTAL_FILL = 'BFBFBF';

    /** Baris pertama judul kolom sheet kelompok. */
    private const GROUP_HEADER_ROW = 6;

    /** Baris judul kolom tabel satu tingkat (REKAP & rincian Payroll). */
    private const LIST_HEADER_ROW = 6;

    /** Jarak baris nama dari baris jabatan (ruang tanda tangan). */
    private const SIGNING_SPACE_ROWS = 8;

    /** @param  array<string, mixed>  $document  hasil PayrollExportDocument::build */
    public function write(array $document): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName(DocumentStyle::FONT)->setSize(DocumentStyle::FONT_SIZE);
        $spreadsheet->removeSheetByIndex(0);

        foreach ($document['sheets'] as $sheetData) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($sheetData['title']);

            match ($sheetData['type']) {
                'group' => $this->writeGroup($sheet, $sheetData, $document),
                'rekap', 'list' => $this->writeTableSheet($sheet, $sheetData, $document),
            };
        }
        $spreadsheet->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'daftar-upah-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    // -------------------------------------------------------------------------
    // Sheet per kelompok
    // -------------------------------------------------------------------------

    private function writeGroup(Worksheet $sheet, array $data, array $document): void
    {
        $columns = PayrollExportDocument::GROUP_COLUMNS;
        $count = count($columns);
        $lastColumn = Coordinate::stringFromColumnIndex($count);
        /** Huruf kolom dari kuncinya, mis. 'name' -> C. */
        $letterOf = fn (string $key) => Coordinate::stringFromColumnIndex(array_search($key, array_column($columns, 0), true) + 1);
        $this->setWidths($sheet, array_map(fn (array $column) => $column[3], $columns));

        // Kop, judul (kolom F s.d. 5 kolom sebelum terakhir), bulan & halaman (2 kolom sebelum terakhir).
        $titleUntil = Coordinate::stringFromColumnIndex($count - 5);
        $monthColumn = Coordinate::stringFromColumnIndex($count - 2);
        $sheet->setCellValue('A1', PayrollExportText::COMPANY);
        $sheet->setCellValue('A2', PayrollExportText::UNIT);
        $sheet->setCellValue('F1', PayrollExportDocument::TITLE);
        $sheet->setCellValue('F2', 'UNIT : AGROWISATA');
        $sheet->mergeCells("F1:{$titleUntil}1");
        $sheet->mergeCells("F2:{$titleUntil}2");
        $sheet->getStyle('F1:F2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->setCellValue("{$monthColumn}1", 'Bulan : '.$document['month_label']);
        $sheet->setCellValue("{$monthColumn}2", 'Hal. : '.$data['page']);
        $sheet->getStyle("A1:{$lastColumn}2")->getFont()->setBold(true);
        $sheet->getStyle('F1')->getFont()->setSize(13);

        $sheet->setCellValue('A3', "{$data['group_label']} : {$data['name']}");
        $sheet->getStyle('A3')->getFont()->setBold(true);
        if ($data['sub_units'] !== '') {
            $sheet->setCellValue('A4', 'Sub Unit : '.$data['sub_units']);
        }
        $this->writeDraftMark($sheet, $document, "{$monthColumn}3");

        // Judul kolom dua tingkat.
        $top = self::GROUP_HEADER_ROW;
        $bottom = $top + 1;
        $groupSpans = [];   // judul atas bersama => [kolom awal, kolom akhir]; digabung SEKALI (gabungan bertumpuk merusak file)
        foreach ($columns as $index => [, $topTitle, $subTitle]) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            if ($subTitle === null) {
                $sheet->setCellValue("{$letter}{$top}", $topTitle);
                $sheet->mergeCells("{$letter}{$top}:{$letter}{$bottom}");

                continue;
            }

            $sheet->setCellValue("{$letter}{$bottom}", $subTitle);
            if (! isset($groupSpans[$topTitle])) {
                $sheet->setCellValue("{$letter}{$top}", $topTitle);
                $groupSpans[$topTitle] = [$letter, $letter];
            }
            $groupSpans[$topTitle][1] = $letter;
        }
        foreach ($groupSpans as [$from, $to]) {
            if ($from !== $to) {
                $sheet->mergeCells("{$from}{$top}:{$to}{$top}");
            }
        }
        $this->styleHeader($sheet, "A{$top}:{$lastColumn}{$bottom}");

        // Data per objek penempatan + JUMLAH TOTAL.
        $keys = array_column($columns, 0);
        [$totalRow, $sectionRows, $subtotalRows] = $this->writeSections($sheet, $data, $keys, $bottom + 1, PayrollExportDocument::GROUP_LABEL_COLUMNS);
        $nameLetters = [$letterOf('name')];
        $this->styleTable($sheet, $bottom + 1, $totalRow, $lastColumn, array_map(fn (array $column) => $column[4], $columns), $nameLetters);
        $this->styleSections($sheet, $sectionRows, $subtotalRows, $totalRow, $lastColumn);
        // Lebar kolom mengikuti tulisan terpanjang (satu baris); nama orang, Jabatan & Uraian cost center boleh berlipat.
        $wrapColumns = [...array_fill_keys($nameLetters, DocumentStyle::NAME_COLUMN_WIDTH), $letterOf('position') => 26, $letterOf('cost_center_name') => 26];
        foreach (array_keys($wrapColumns) as $letter) {
            $sheet->getStyle($letter.($bottom + 1).":{$letter}{$totalRow}")->getAlignment()->setWrapText(true);
        }
        ExcelColumnFit::apply($sheet, $top, $totalRow, $wrapColumns);

        $this->writeSignature($sheet, $data['signature'], $totalRow + 2, count($columns));
        $this->setupPage($sheet, PageSetup::ORIENTATION_LANDSCAPE, $top, $bottom);
        $sheet->freezePane('D'.($bottom + 1));
    }

    // -------------------------------------------------------------------------
    // Tabel satu tingkat: REKAP (semua dokumen) & rincian Payroll per kelompok
    // -------------------------------------------------------------------------

    /**
     * Kop + judul (heading) + "Bulan : …", untuk rincian Payroll juga "<Kelompok> : <nama>", lalu tabel (columns) +
     * JUMLAH (label digabung selebar label_span kolom) + tanda tangan. REKAP mendatar, rincian Payroll tegak.
     */
    public function writeTableSheet(Worksheet $sheet, array $data, array $document): void
    {
        $columnCount = count($data['columns']);
        $this->writeListTitle($sheet, $data['heading'], $document, $columnCount);
        if ($data['type'] === 'list') {
            $sheet->setCellValue('A5', "{$data['group_label']} : {$data['name']}");
            $sheet->getStyle('A5')->getFont()->setBold(true);
        }

        $labelUntil = Coordinate::stringFromColumnIndex($data['label_span']);
        $totalRow = $this->writeList($sheet, $data['columns'], $data, $labelUntil, PayrollExportDocument::NAME_KEYS);
        $this->writeSignature($sheet, $data['signature'], $totalRow + 2, $columnCount);

        $isRekap = $data['type'] === 'rekap';
        $this->setupPage($sheet, $isRekap ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT, self::LIST_HEADER_ROW, self::LIST_HEADER_ROW);
        if (! $isRekap) {
            $sheet->freezePane('C'.(self::LIST_HEADER_ROW + 1));
        }
    }

    private function writeListTitle(Worksheet $sheet, string $title, array $document, int $columnCount): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        $sheet->setCellValue('A1', PayrollExportText::COMPANY);
        $sheet->setCellValue('A2', PayrollExportText::UNIT);
        $sheet->setCellValue('A3', $title);
        $sheet->setCellValue('A4', 'Bulan : '.$document['month_label']);
        $sheet->mergeCells("A3:{$lastColumn}3");
        $sheet->mergeCells("A4:{$lastColumn}4");
        $sheet->getStyle('A3:A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A1:A3')->getFont()->setBold(true);
        $sheet->getStyle('A3')->getFont()->setSize(14);
        $this->writeDraftMark($sheet, $document, $lastColumn.'2');
    }

    /**
     * Tabel satu tingkat judul + JUMLAH (label digabung sampai kolom $labelUntil). Mengembalikan nomor baris JUMLAH.
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: bool|string}>  $columns  format: true = rupiah, 'hours' = jam
     * @param  list<string>  $nameKeys  kolom nama orang: sempit, berlipat, rata kiri
     */
    private function writeList(Worksheet $sheet, array $columns, array $data, string $labelUntil, array $nameKeys = []): int
    {
        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $this->setWidths($sheet, array_map(fn (array $column) => $column[2], $columns));

        $header = self::LIST_HEADER_ROW;
        foreach ($columns as $index => [, $title]) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).$header, $title);
        }
        $this->styleHeader($sheet, "A{$header}:{$lastColumn}{$header}");

        $sectionRows = $subtotalRows = [];
        if (isset($data['sections'])) {
            // Rincian Payroll: per objek penempatan + JUMLAH TOTAL.
            [$rowNumber, $sectionRows, $subtotalRows] = $this->writeSections($sheet, $data, array_column($columns, 0), $header + 1, Coordinate::columnIndexFromString($labelUntil));
        } else {
            $rowNumber = $header + 1;
            foreach ($data['rows'] as $row) {
                foreach ($columns as $index => [$key]) {
                    self::setValue($sheet, Coordinate::stringFromColumnIndex($index + 1).$rowNumber, $row[$key]);
                }
                $rowNumber++;
            }

            $sheet->setCellValue("A{$rowNumber}", 'JUMLAH');
            $sheet->mergeCells("A{$rowNumber}:{$labelUntil}{$rowNumber}");
            foreach ($columns as $index => [$key]) {
                if ($key !== 'no' && array_key_exists($key, $data['totals'])) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).$rowNumber, $data['totals'][$key]);
                }
            }
        }
        $nameLetters = [];
        foreach ($columns as $index => [$key]) {
            if (in_array($key, $nameKeys, true)) {
                $nameLetters[] = Coordinate::stringFromColumnIndex($index + 1);
            }
        }
        $this->styleTable($sheet, $header + 1, $rowNumber, $lastColumn, array_map(fn (array $column) => $column[3], $columns), $nameLetters);
        $this->styleSections($sheet, $sectionRows, $subtotalRows, $rowNumber, $lastColumn);
        // Lebar kolom mengikuti tulisan terpanjang (satu baris); kolom nama (Nama / Atas Nama / Uraian) berlipat.
        $wrapColumns = [];
        foreach ($nameLetters as $letter) {
            $wrapColumns[$letter] = DocumentStyle::NAME_COLUMN_WIDTH;
            $sheet->getStyle($letter.($header + 1).":{$letter}{$rowNumber}")->getAlignment()->setWrapText(true);
        }
        ExcelColumnFit::apply($sheet, $header, $rowNumber, $wrapColumns);

        return $rowNumber;
    }

    // -------------------------------------------------------------------------
    // Objek penempatan (sheet kelompok Daftar Upah & rincian Payroll)
    // -------------------------------------------------------------------------

    /**
     * Per objek: baris judul objek (digabung kolom 1 s.d. $labelSpan), baris karyawan, baris JUMLAH objek (No = jumlah
     * karyawan, tulisan JUMLAH digabung kolom 2 s.d. $labelSpan); terakhir baris JUMLAH TOTAL.
     *
     * @param  list<string>  $keys  kunci kolom, urut kiri ke kanan
     * @return array{0: int, 1: list<int>, 2: list<int>} [baris JUMLAH TOTAL, baris judul objek, baris JUMLAH objek]
     */
    private function writeSections(Worksheet $sheet, array $data, array $keys, int $firstRow, int $labelSpan): array
    {
        $labelUntil = Coordinate::stringFromColumnIndex($labelSpan);
        $writeTotals = function (int $rowNumber, array $totals) use ($sheet, $keys, $labelSpan): void {
            foreach ($keys as $index => $key) {
                if ($index >= $labelSpan && array_key_exists($key, $totals)) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).$rowNumber, $totals[$key]);
                }
            }
        };

        $rowNumber = $firstRow;
        $sectionRows = $subtotalRows = [];
        foreach ($data['sections'] as $section) {
            $sheet->setCellValue("A{$rowNumber}", $section['name']);
            $sheet->mergeCells("A{$rowNumber}:{$labelUntil}{$rowNumber}");
            $sectionRows[] = $rowNumber++;

            foreach ($section['rows'] as $row) {
                foreach ($keys as $index => $key) {
                    self::setValue($sheet, Coordinate::stringFromColumnIndex($index + 1).$rowNumber, $row[$key]);
                }
                $rowNumber++;
            }

            $sheet->setCellValue("A{$rowNumber}", count($section['rows']));
            $sheet->setCellValue("B{$rowNumber}", PayrollExportDocument::SECTION_TOTAL_LABEL);
            $sheet->mergeCells("B{$rowNumber}:{$labelUntil}{$rowNumber}");
            $writeTotals($rowNumber, $section['totals']);
            $subtotalRows[] = $rowNumber++;
        }

        $sheet->setCellValue("A{$rowNumber}", PayrollExportDocument::GRAND_TOTAL_LABEL);
        $sheet->mergeCells("A{$rowNumber}:{$labelUntil}{$rowNumber}");
        $writeTotals($rowNumber, $data['totals']);

        return [$rowNumber, $sectionRows, $subtotalRows];
    }

    /**
     * Judul objek tebal, bergaris bawah, lebih besar, rata kiri; baris JUMLAH objek tebal berlatar abu-abu;
     * JUMLAH TOTAL tebal berlatar biru muda (warna judul kolom).
     *
     * @param  list<int>  $sectionRows
     * @param  list<int>  $subtotalRows
     */
    private function styleSections(Worksheet $sheet, array $sectionRows, array $subtotalRows, int $totalRow, string $lastColumn): void
    {
        foreach ($sectionRows as $row) {
            $style = $sheet->getStyle("A{$row}:{$lastColumn}{$row}");
            $style->getFont()->setBold(true)->setUnderline(Font::UNDERLINE_SINGLE)->setSize(DocumentStyle::FONT_SIZE + 2);
            $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setIndent(1);
            $sheet->getRowDimension($row)->setRowHeight(24);
        }
        foreach ($subtotalRows as $row) {
            $style = $sheet->getStyle("A{$row}:{$lastColumn}{$row}");
            $style->getFont()->setBold(true);
            $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setIndent(0);
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::SECTION_TOTAL_FILL);
        }
        if ($sectionRows !== []) {
            $sheet->getStyle("A{$totalRow}:{$lastColumn}{$totalRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER_FILL);
        }
    }

    // -------------------------------------------------------------------------
    // Bagian bersama
    // -------------------------------------------------------------------------

    /**
     * "<tempat>, <tanggal>" di kanan, lalu per bagian: label (digabung selebar bagian), jabatan, ruang tanda tangan,
     * dan nama bergaris bawah — orang dalam satu bagian berdampingan.
     */
    public static function writeSignatureBlock(Worksheet $sheet, array $signature, int $startRow, int $columnCount): int
    {
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        $placeFrom = Coordinate::stringFromColumnIndex(max($columnCount - 3, 1));
        $sheet->setCellValue("{$placeFrom}{$startRow}", $signature['place_date']);
        $sheet->mergeCells("{$placeFrom}{$startRow}:{$lastColumn}{$startRow}");
        $sheet->getStyle("{$placeFrom}{$startRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $labelRow = $startRow + 1;
        $positionRow = $labelRow + 1;
        $nameRow = $positionRow + self::SIGNING_SPACE_ROWS;

        foreach (PayrollExportText::signatureColumns($signature['sections'], $columnCount) as $section) {
            $from = Coordinate::stringFromColumnIndex($section['from']);
            $to = Coordinate::stringFromColumnIndex($section['to']);
            $sheet->setCellValue("{$from}{$labelRow}", $section['label']);
            $sheet->mergeCells("{$from}{$labelRow}:{$to}{$labelRow}");

            foreach ($section['signers'] as $signer) {
                $signerFrom = Coordinate::stringFromColumnIndex($signer['from']);
                $signerTo = Coordinate::stringFromColumnIndex($signer['to']);
                $sheet->setCellValue("{$signerFrom}{$positionRow}", $signer['position']);
                $sheet->setCellValue("{$signerFrom}{$nameRow}", $signer['name']);
                if ($signer['from'] !== $signer['to']) {
                    $sheet->mergeCells("{$signerFrom}{$positionRow}:{$signerTo}{$positionRow}");
                    $sheet->mergeCells("{$signerFrom}{$nameRow}:{$signerTo}{$nameRow}");
                }
            }
        }

        $sheet->getStyle("A{$labelRow}:{$lastColumn}{$nameRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
        $sheet->getStyle("A{$nameRow}:{$lastColumn}{$nameRow}")->getFont()->setBold(true)->setUnderline(Font::UNDERLINE_SINGLE);

        return $nameRow;
    }

    private function writeSignature(Worksheet $sheet, array $signature, int $startRow, int $columnCount): void
    {
        $nameRow = self::writeSignatureBlock($sheet, $signature, $startRow, $columnCount);
        $sheet->getPageSetup()->setPrintArea('A1:'.Coordinate::stringFromColumnIndex($columnCount).$nameRow);
    }

    /** Angka ditulis sebagai angka; teks (mis. No. Registrasi "00123") tetap teks; null = sel kosong. */
    public static function setValue(Worksheet $sheet, string $cell, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        $sheet->setCellValueExplicit($cell, $value, is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
    }

    private function writeDraftMark(Worksheet $sheet, array $document, string $cell): void
    {
        if ($document['is_draft']) {
            $sheet->setCellValue($cell, 'DRAF');
            $sheet->getStyle($cell)->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('C00000');
        }
    }

    /** @param  list<int>  $widths */
    private function setWidths(Worksheet $sheet, array $widths): void
    {
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }
    }

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER_FILL);
        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    /** @param  list<bool>  $isMoney */
    /**
     * Garis, baris JUMLAH tebal, format rupiah, dan perataan: semua isi rata tengah, KECUALI kolom nama
     * (rata kiri dengan jarak dari garis).
     *
     * @param  list<bool>  $isMoney
     * @param  list<string>  $nameLetters  kolom nama (Nama / Atas Nama)
     */
    private function styleTable(Worksheet $sheet, int $firstRow, int $totalRow, string $lastColumn, array $isMoney, array $nameLetters): void
    {
        $sheet->getStyle("A{$firstRow}:{$lastColumn}{$totalRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A{$firstRow}:{$lastColumn}{$totalRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);   // baris bisa tinggi kalau nama berlipat
        foreach ($nameLetters as $letter) {
            $sheet->getStyle("{$letter}{$firstRow}:{$letter}".($totalRow - 1))->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT)->setIndent(1);
        }
        $sheet->getStyle("A{$totalRow}:{$lastColumn}{$totalRow}")->getFont()->setBold(true);

        foreach ($isMoney as $index => $format) {
            $code = match ($format) {
                true => self::MONEY_FORMAT,
                'hours' => self::HOURS_FORMAT,
                default => null,
            };
            if ($code !== null) {
                $letter = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->getStyle("{$letter}{$firstRow}:{$letter}{$totalRow}")->getNumberFormat()->setFormatCode($code);
            }
        }
    }

    private function setupPage(Worksheet $sheet, string $orientation, int $repeatFrom, int $repeatTo): void
    {
        $setup = $sheet->getPageSetup();
        $setup->setOrientation($orientation)->setPaperSize(PageSetup::PAPERSIZE_FOLIO);   // F4 (8,5 × 13 inci)
        $setup->setFitToWidth(1)->setFitToHeight(0);
        $setup->setRowsToRepeatAtTopByStartAndEnd($repeatFrom, $repeatTo);
        $sheet->getPageMargins()->setLeft(0.3)->setRight(0.3)->setTop(0.4)->setBottom(0.4);
    }
}
