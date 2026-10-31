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
 *   baris 8…    data, lalu baris JUMLAH, lalu "<tempat>, <tanggal>" & blok tanda tangan
 *
 * Ditambah sheet REKAP (semua kelompok) dan PAYROLL (rekening). Rupiah tanpa desimal.
 */
class PayrollExportWorkbook
{
    private const MONEY_FORMAT = '#,##0';

    private const HEADER_FILL = 'D9E1F2';

    /** Baris pertama judul kolom sheet kelompok. */
    private const GROUP_HEADER_ROW = 6;

    /** Baris judul kolom sheet REKAP & PAYROLL. */
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
                'rekap' => $this->writeRekap($sheet, $sheetData, $document),
                'payroll' => $this->writePayroll($sheet, $sheetData, $document),
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
        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $this->setWidths($sheet, array_map(fn (array $column) => $column[3], $columns));

        // Kop, judul, bulan & halaman.
        $sheet->setCellValue('A1', PayrollExportText::COMPANY);
        $sheet->setCellValue('A2', PayrollExportText::UNIT);
        $sheet->setCellValue('F1', PayrollExportDocument::TITLE);
        $sheet->setCellValue('F2', 'UNIT : AGROWISATA');
        $sheet->mergeCells('F1:P1');
        $sheet->mergeCells('F2:P2');
        $sheet->getStyle('F1:F2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->setCellValue('S1', 'Bulan : '.$document['month_label']);
        $sheet->setCellValue('S2', 'Hal. : '.$data['page']);
        $sheet->getStyle('A1:U2')->getFont()->setBold(true);
        $sheet->getStyle('F1')->getFont()->setSize(13);

        $sheet->setCellValue('A3', "{$data['group_label']} : {$data['name']}");
        $sheet->getStyle('A3')->getFont()->setBold(true);
        if ($data['sub_units'] !== '') {
            $sheet->setCellValue('A4', 'Sub Unit : '.$data['sub_units']);
        }
        $this->writeDraftMark($sheet, $document, 'S3');

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

        // Data + JUMLAH.
        $rowNumber = $bottom + 1;
        foreach ($data['rows'] as $row) {
            foreach ($columns as $index => [$key]) {
                self::setValue($sheet, Coordinate::stringFromColumnIndex($index + 1).$rowNumber, $row[$key]);
            }
            $rowNumber++;
        }
        $totalRow = $rowNumber;
        $sheet->setCellValue("A{$totalRow}", 'JUMLAH');
        $sheet->mergeCells("A{$totalRow}:F{$totalRow}");
        foreach ($columns as $index => [$key]) {
            if (array_key_exists($key, $data['totals'])) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).$totalRow, $data['totals'][$key]);
            }
        }
        $this->styleTable($sheet, $bottom + 1, $totalRow, $lastColumn, array_map(fn (array $column) => $column[4], $columns), ['C']);
        // Lebar kolom mengikuti tulisan terpanjang (satu baris); Nama, Jabatan & Uraian cost center boleh berlipat.
        $wrapColumns = ['C' => DocumentStyle::NAME_COLUMN_WIDTH, 'D' => 26, 'F' => 26];
        foreach (array_keys($wrapColumns) as $letter) {
            $sheet->getStyle($letter.($bottom + 1).":{$letter}{$totalRow}")->getAlignment()->setWrapText(true);
        }
        ExcelColumnFit::apply($sheet, $top, $totalRow, $wrapColumns);

        $this->writeSignature($sheet, $data['signature'], $totalRow + 2, count($columns));
        $this->setupPage($sheet, PageSetup::ORIENTATION_LANDSCAPE, $top, $bottom);
        $sheet->freezePane('D'.($bottom + 1));
    }

    // -------------------------------------------------------------------------
    // REKAP & PAYROLL
    // -------------------------------------------------------------------------

    private function writeRekap(Worksheet $sheet, array $data, array $document): void
    {
        $this->writeListTitle($sheet, 'REKAPITULASI '.PayrollExportDocument::TITLE, $document, count(PayrollExportDocument::REKAP_COLUMNS));
        $totalRow = $this->writeList($sheet, PayrollExportDocument::REKAP_COLUMNS, $data, 'B');
        $this->writeSignature($sheet, $data['signature'], $totalRow + 2, count(PayrollExportDocument::REKAP_COLUMNS));
        $this->setupPage($sheet, PageSetup::ORIENTATION_LANDSCAPE, self::LIST_HEADER_ROW, self::LIST_HEADER_ROW);
    }

    private function writePayroll(Worksheet $sheet, array $data, array $document): void
    {
        $this->writeListTitle($sheet, 'PAYROLL '.PayrollExportDocument::TITLE, $document, count(PayrollExportDocument::PAYROLL_COLUMNS));
        $this->writeList($sheet, PayrollExportDocument::PAYROLL_COLUMNS, $data, 'F', ['name', 'account_name']);
        $this->setupPage($sheet, PageSetup::ORIENTATION_PORTRAIT, self::LIST_HEADER_ROW, self::LIST_HEADER_ROW);
        $sheet->freezePane('D'.(self::LIST_HEADER_ROW + 1));
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
     * @param  list<array{0: string, 1: string, 2: int, 3: bool}>  $columns
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
        $nameLetters = [];
        foreach ($columns as $index => [$key]) {
            if (in_array($key, $nameKeys, true)) {
                $nameLetters[] = Coordinate::stringFromColumnIndex($index + 1);
            }
        }
        $this->styleTable($sheet, $header + 1, $rowNumber, $lastColumn, array_map(fn (array $column) => $column[3], $columns), $nameLetters);
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

        foreach ($isMoney as $index => $money) {
            if ($money) {
                $letter = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->getStyle("{$letter}{$firstRow}:{$letter}{$totalRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
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
