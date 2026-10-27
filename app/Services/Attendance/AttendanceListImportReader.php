<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceImportType;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Membaca file DAFTAR HADIR yang diunggah menjadi baris mentah:
 *
 *   [['row' => 4, 'employee_id' => '…', 'registration_number' => '7761', 'name' => 'A. ROHMAN',
 *     'codes' => ['2026-10-01' => 'K', '2026-10-02' => null, …]], …]
 *
 * Kolom tanggal harus persis tanggal 1..akhir bulan periode; kalau tidak, seluruh file ditolak 422.
 */
class AttendanceListImportReader
{
    /** @return list<array{row: int, employee_id: ?string, registration_number: ?string, name: ?string, codes: array<string, ?string>}> */
    public function rows(UploadedFile $file, AttendancePeriod $period): array
    {
        $spreadsheet = ImportWorkbook::open(
            $file,
            [AttendanceListLayout::SHEET_TITLE, AttendanceListLayout::LIST_SHEET_TITLE],
            $period,
            AttendanceImportType::AttendanceList,
            AttendanceListLayout::PERIOD_CELL,
            AttendanceListLayout::TYPE_CELL,
        );
        $sheet = $spreadsheet->getSheetByName(AttendanceListLayout::SHEET_TITLE);
        $dates = $period->dates();

        foreach ($dates as $index => $date) {
            $header = $sheet->getCell(AttendanceListLayout::dateLetter($index + 1).AttendanceListLayout::HEADER_ROW)->getValue();
            if ((int) $header !== $index + 1) {
                $spreadsheet->disconnectWorksheets();
                throw ValidationException::withMessages(['file' => 'Kolom tanggal tidak sesuai periode '.$period->label().'. Unduh template periode ini lalu isi ulang.']);
            }
        }

        $identity = AttendanceListLayout::IDENTITY_COLUMNS;
        $rows = [];
        for ($row = AttendanceListLayout::FIRST_DATA_ROW; $row <= $sheet->getHighestDataRow(); $row++) {
            $codes = [];
            foreach ($dates as $index => $date) {
                $codes[$date] = ImportWorkbook::text($sheet->getCell(AttendanceListLayout::dateLetter($index + 1).$row)->getValue());
            }
            $item = [
                'row' => $row,
                'employee_id' => ImportWorkbook::text($sheet->getCell($identity['employee_id'][0].$row)->getValue()),
                'registration_number' => ImportWorkbook::text($sheet->getCell($identity['registration_number'][0].$row)->getValue()),
                'name' => ImportWorkbook::text($sheet->getCell($identity['name'][0].$row)->getValue()),
                'codes' => $codes,
            ];

            if ($item['employee_id'] === null && $item['registration_number'] === null && array_filter($codes) === []) {
                continue;
            }
            $rows[] = $item;
        }

        $spreadsheet->disconnectWorksheets();

        return $rows;
    }
}
