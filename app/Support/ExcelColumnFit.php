<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * LEBAR KOLOM EXCEL OTOMATIS supaya tulisan muat SATU BARIS: lebar = teks terpanjang di kolom itu
 * (judul & isi, angka dihitung setelah diformat, mis. 2.547.600). Sel gabungan lebih dari satu kolom dilewati.
 * Kolom yang memang boleh berlipat (mis. uraian, jabatan) diberi lebar tetap lewat $wrapColumns.
 *
 *   ExcelColumnFit::apply($sheet, 6, 40, ['D' => 26, 'F' => 26]);
 */
class ExcelColumnFit
{
    /** Pengali lebar huruf Tahoma 11 terhadap satuan lebar kolom Excel, ditambah ruang kiri-kanan. */
    private const CHAR_FACTOR = 1.15;

    private const PADDING = 2;

    private const MIN_WIDTH = 5;

    /**
     * @param  array<string, int|float>  $wrapColumns  [huruf => lebar tetap] kolom yang tulisannya boleh berlipat
     */
    public static function apply(Worksheet $sheet, int $fromRow, int $toRow, array $wrapColumns = []): void
    {
        self::applyToRanges($sheet, [[$fromRow, $toRow]], $wrapColumns);
    }

    /**
     * Sama dengan apply(), tetapi hanya baris-baris di rentang yang diberikan (mis. tabel setiap blok SPKL).
     *
     * @param  list<array{int, int}>  $rowRanges  [[baris awal, baris akhir], ...]
     * @param  array<string, int|float>  $wrapColumns
     */
    public static function applyToRanges(Worksheet $sheet, array $rowRanges, array $wrapColumns = []): void
    {
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $multiColumnMerged = self::multiColumnMergedCells($sheet);
        $lengths = [];
        $rows = array_merge(...array_map(fn (array $range) => range($range[0], $range[1]), $rowRanges ?: [[1, 0]]));

        foreach ($rows as $row) {
            for ($column = 1; $column <= $lastColumn; $column++) {
                $letter = Coordinate::stringFromColumnIndex($column);
                if (isset($multiColumnMerged["{$letter}{$row}"]) || ! $sheet->cellExists("{$letter}{$row}")) {
                    continue;
                }
                $value = $sheet->getCell("{$letter}{$row}")->getValue();
                // Format angka hanya dibaca untuk sel angka (membaca gaya setiap sel lambat untuk ribuan baris).
                $format = is_int($value) || is_float($value) ? $sheet->getStyle("{$letter}{$row}")->getNumberFormat()->getFormatCode() : '';
                $text = self::displayText($value, $format);
                $length = max(array_map('mb_strlen', explode("\n", $text)));   // tulisan berbaris-baris: baris terpanjang
                $lengths[$letter] = max($lengths[$letter] ?? 0, $length);
            }
        }

        foreach ($lengths as $letter => $length) {
            $width = $wrapColumns[$letter] ?? max(self::MIN_WIDTH, $length * self::CHAR_FACTOR + self::PADDING);
            $sheet->getColumnDimension($letter)->setWidth($width);
        }
        foreach ($wrapColumns as $letter => $width) {
            $sheet->getColumnDimension($letter)->setWidth($width);
        }
    }

    /** Teks yang tampil di sel. Hasil rumus tidak diketahui di sini, jadi dianggap 10 huruf. */
    private static function displayText(mixed $value, string $format): string
    {
        return match (true) {
            $value === null => '',
            is_string($value) && str_starts_with($value, '=') => str_repeat('0', 10),
            is_int($value) || is_float($value) => $format === NumberFormat::FORMAT_GENERAL || $format === ''
                ? (string) $value
                : NumberFormat::toFormattedString($value, $format),
            default => (string) $value,
        };
    }

    /** @return array<string, true> sel yang termasuk gabungan lebih dari satu kolom */
    private static function multiColumnMergedCells(Worksheet $sheet): array
    {
        $cells = [];
        foreach ($sheet->getMergeCells() as $range) {
            [[$fromColumn, $fromRow], [$toColumn, $toRow]] = Coordinate::rangeBoundaries($range);
            if ($fromColumn === $toColumn) {
                continue;
            }
            for ($column = $fromColumn; $column <= $toColumn; $column++) {
                for ($row = $fromRow; $row <= $toRow; $row++) {
                    $cells[Coordinate::stringFromColumnIndex($column).$row] = true;
                }
            }
        }

        return $cells;
    }
}
