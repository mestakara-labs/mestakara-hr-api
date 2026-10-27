<?php

namespace App\Services\Payroll;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Membaca file template Skema Upah yang diunggah menjadi baris mentah:
 *
 *   [['row' => 5, 'values' => ['employee_id' => '...', 'base_salary' => 2500000, ...]], ...]
 *
 * Nilai sel dibiarkan apa adanya (angka, teks, tanggal Excel); pemeriksaannya di WageSchemeImportChecker.
 * File yang bukan template (sheet / judul kolom berbeda) ditolak 422.
 */
class WageSchemeImportReader
{
    private const NOT_A_TEMPLATE = 'File bukan template skema upah. Unduh template terbaru, isi, lalu unggah kembali tanpa mengubah judul kolom.';

    /** @return list<array{row: int, values: array<string, mixed>}> */
    public function rows(UploadedFile $file): array
    {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $sheetNames = $reader->listWorksheetNames($file->getRealPath());
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'File Excel tidak bisa dibaca. Pastikan formatnya XLSX.']);
        }

        if (! in_array(WageSchemeTemplateColumns::SHEET_TITLE, $sheetNames, true)) {
            throw ValidationException::withMessages(['file' => self::NOT_A_TEMPLATE]);
        }

        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([WageSchemeTemplateColumns::SHEET_TITLE]);
        $spreadsheet = $reader->load($file->getRealPath());

        $sheet = $spreadsheet->getSheetByName(WageSchemeTemplateColumns::SHEET_TITLE);
        if ($sheet === null || ! $this->hasTemplateHeader($sheet)) {
            $spreadsheet->disconnectWorksheets();
            throw ValidationException::withMessages(['file' => self::NOT_A_TEMPLATE]);
        }

        $rows = [];
        $highestRow = $sheet->getHighestDataRow();
        for ($rowNumber = WageSchemeTemplateColumns::FIRST_DATA_ROW; $rowNumber <= $highestRow; $rowNumber++) {
            $values = [];
            foreach (WageSchemeTemplateColumns::COLUMNS as $column) {
                $value = $sheet->getCell($column['letter'].$rowNumber)->getValue();
                $values[$column['key']] = is_string($value) ? trim($value) : $value;
            }

            if ($this->isEmptyRow($values)) {
                continue;
            }

            $rows[] = ['row' => $rowNumber, 'values' => $values];
        }

        $spreadsheet->disconnectWorksheets();

        return $rows;
    }

    private function hasTemplateHeader(Worksheet $sheet): bool
    {
        foreach (WageSchemeTemplateColumns::COLUMNS as $column) {
            if (trim((string) $sheet->getCell($column['letter'].WageSchemeTemplateColumns::HEADER_ROW)->getValue()) !== $column['header']) {
                return false;
            }
        }

        return true;
    }

    /** Baris tanpa isian sama sekali (selain kolom info) dilewati. */
    private function isEmptyRow(array $values): bool
    {
        foreach (WageSchemeTemplateColumns::COLUMNS as $column) {
            if ($column['group'] !== 'info' && $values[$column['key']] !== null && $values[$column['key']] !== '') {
                return false;
            }
        }

        return true;
    }
}
