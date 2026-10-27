<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceImportType;
use Illuminate\Http\UploadedFile;

/**
 * Membaca file LEMBUR REGULER / LEMBUR DW yang diunggah menjadi baris mentah (nilai sel apa adanya;
 * tanggal & jam dibaca oleh OvertimeImportChecker):
 *
 *   [['row' => 3, 'registration_number' => '7761', 'date' => 46296, 'start_time' => 0.7083, 'end_time' => '19.00',
 *     'description' => 'Persiapan acara'], …]
 */
class OvertimeImportReader
{
    /** @return list<array{row: int, registration_number: ?string, date: mixed, start_time: mixed, end_time: mixed, description: ?string}> */
    public function rows(UploadedFile $file, AttendancePeriod $period, AttendanceImportType $type): array
    {
        $spreadsheet = ImportWorkbook::open(
            $file,
            [OvertimeLayout::sheetTitle($type), OvertimeLayout::LIST_SHEET_TITLE],
            $period,
            $type,
            OvertimeLayout::PERIOD_CELL,
            OvertimeLayout::TYPE_CELL,
        );
        $sheet = $spreadsheet->getSheetByName(OvertimeLayout::sheetTitle($type));

        $rows = [];
        for ($row = OvertimeLayout::FIRST_DATA_ROW; $row <= $sheet->getHighestDataRow(); $row++) {
            $cell = fn (string $key) => $sheet->getCell(OvertimeLayout::letter($key).$row)->getValue();
            $item = [
                'row' => $row,
                'registration_number' => ImportWorkbook::text($cell('registration_number')),
                'date' => $cell('date'),
                'start_time' => $cell('start_time'),
                'end_time' => $cell('end_time'),
                'description' => ImportWorkbook::text($cell('description')),
            ];

            $filled = array_filter([$item['registration_number'], $item['date'], $item['start_time'], $item['end_time'], $item['description']], fn ($value) => $value !== null && $value !== '');
            if ($filled === []) {
                continue;
            }
            $rows[] = $item;
        }

        $spreadsheet->disconnectWorksheets();

        return $rows;
    }
}
