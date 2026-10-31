<?php

namespace App\Services\Dashboard;

use App\Support\DocumentStyle;
use App\Support\ExcelColumnFit;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * FILE EXCEL ANALITIK SDM (hasil AnalyticsService):
 *
 *   baris 1–4  judul, perusahaan, periode, filter
 *   baris 6    header: No · <Dimensi> · <Metrik> · Porsi (%)      (dengan split_by: No · <Dimensi> · kolom … · Total)
 *   baris 7 …  data, lalu baris Total
 */
class AnalyticsWorkbook
{
    private const HEADER_ROW = 6;

    private const HEADER_GREY = 'D9D9D9';

    private const NUMBER_FORMAT = '#,##0';

    private const PERCENT_FORMAT = '0.0';

    /** Tulis file, kembalikan path file sementaranya. */
    public function write(AnalyticsQuery $query, array $result): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName(DocumentStyle::FONT)->setSize(DocumentStyle::FONT_SIZE);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Analitik SDM');

        $this->writeTitle($sheet, $query);
        $lastRow = $result['type'] === 'matrix'
            ? $this->writeMatrix($sheet, $query, $result['data'])
            : $this->writeRows($sheet, $query, $result);

        ExcelColumnFit::apply($sheet, self::HEADER_ROW, $lastRow);
        $sheet->freezePane('A'.(self::HEADER_ROW + 1));
        $sheet->setSelectedCell('A1');

        $path = tempnam(sys_get_temp_dir(), 'analitik-sdm-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function writeTitle(Worksheet $sheet, AnalyticsQuery $query): void
    {
        $period = $query->period;
        $sheet->setCellValue('A1', "Analitik SDM — {$query->metricLabel()} per {$query->dimensionLabel()}");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', DocumentStyle::COMPANY);
        $sheet->setCellValue('A3', "Periode: {$period->label()} ({$period->from->format('d-m-Y')} s.d. {$period->to->format('d-m-Y')})");
        $sheet->setCellValueExplicit('A4', 'Filter: '.$query->filters->describe(), DataType::TYPE_STRING);
    }

    /** Tanpa split_by: satu baris per kelompok. Mengembalikan nomor baris terakhir. */
    private function writeRows(Worksheet $sheet, AnalyticsQuery $query, array $result): int
    {
        $this->writeHeader($sheet, ['No', Dimensions::LABELS[$query->groupBy], $this->metricHeader($query), 'Porsi (%)']);

        $row = self::HEADER_ROW;
        foreach ($result['rows'] as $index => $item) {
            $row++;
            $sheet->setCellValue("A{$row}", $index + 1);
            $sheet->setCellValueExplicit("B{$row}", $item['label'], DataType::TYPE_STRING);
            $sheet->setCellValue("C{$row}", $item['value']);
            $sheet->setCellValue("D{$row}", $item['share']);
        }
        $row++;
        $sheet->setCellValue("B{$row}", 'Total');
        $sheet->setCellValue("C{$row}", $result['summary']['total']);

        $this->styleTable($sheet, 'D', $row);
        $sheet->getStyle('C'.(self::HEADER_ROW + 1).":C{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
        $sheet->getStyle('D'.(self::HEADER_ROW + 1).":D{$row}")->getNumberFormat()->setFormatCode(self::PERCENT_FORMAT);

        return $row;
    }

    /** Dengan split_by: kelompok × kolom dimensi kedua, ditutup kolom & baris Total. */
    private function writeMatrix(Worksheet $sheet, AnalyticsQuery $query, array $matrix): int
    {
        $headers = ['No', Dimensions::LABELS[$query->groupBy], ...array_column($matrix['columns'], 'label'), 'Total'];
        $this->writeHeader($sheet, $headers);
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));

        $row = self::HEADER_ROW;
        foreach ($matrix['rows'] as $index => $item) {
            $row++;
            $sheet->setCellValue("A{$row}", $index + 1);
            $sheet->setCellValueExplicit("B{$row}", $item['label'], DataType::TYPE_STRING);
            $sheet->fromArray([...$matrix['values'][$index], $matrix['row_totals'][$index]], null, "C{$row}", true);
        }
        $row++;
        $sheet->setCellValue("B{$row}", 'Total');
        $sheet->fromArray([...$matrix['column_totals'], $matrix['total']], null, "C{$row}", true);

        $this->styleTable($sheet, $lastColumn, $row);
        $sheet->getStyle('C'.(self::HEADER_ROW + 1).":{$lastColumn}{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);

        return $row;
    }

    /** @param  list<string>  $headers */
    private function writeHeader(Worksheet $sheet, array $headers): void
    {
        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($index + 1).self::HEADER_ROW, $header, DataType::TYPE_STRING);
        }
    }

    /** Garis tabel, header abu-abu tebal, baris Total tebal, No rata tengah. */
    private function styleTable(Worksheet $sheet, string $lastColumn, int $lastRow): void
    {
        $sheet->getStyle('A'.self::HEADER_ROW.":{$lastColumn}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $header = $sheet->getStyle('A'.self::HEADER_ROW.":{$lastColumn}".self::HEADER_ROW);
        $header->getFont()->setBold(true);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER_GREY);
        $header->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getStyle("A{$lastRow}:{$lastColumn}{$lastRow}")->getFont()->setBold(true);
        $sheet->getStyle('A'.self::HEADER_ROW.":A{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private function metricHeader(AnalyticsQuery $query): string
    {
        return $query->metricLabel().($query->unit() === 'rupiah' ? ' (Rp)' : ' (orang)');
    }
}
