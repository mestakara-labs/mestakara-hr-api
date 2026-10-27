<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceSource;
use App\Enums\DocumentScope;
use App\Enums\ProposalStatus;
use App\Models\Employee;
use App\Models\EmployeeTermination;
use App\Models\OvertimeEntry;
use App\Models\User;
use App\Services\Attendance\AttendanceDayService;
use App\Services\Attendance\AttendanceListLayout;
use App\Services\Attendance\OvertimeLayout;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Pembantu pengujian Unggah Absensi: membuat karyawan, mengunduh template, mengisinya, dan mengunggah.
 * Periode uji: Oktober 2026 (hari ini 20 Oktober 2026).
 */
trait AttendanceTestHelpers
{
    protected function casual(string $name, ?string $registration = null, string $status = 'Karyawan Casual'): Employee
    {
        return Employee::factory()->active()->create([
            'name' => $name, 'employment_status' => $status, 'nik' => null, 'registration_number' => $registration,
        ]);
    }

    protected function terminate(Employee $employee, string $effectiveDate): void
    {
        EmployeeTermination::create([
            'status' => ProposalStatus::Effective,
            'document_scope' => DocumentScope::Collective,
            'created_by' => User::factory()->create()->id,
            'activated_at' => now(),
        ])->items()->create(['employee_id' => $employee->id, 'type' => 'Mengundurkan Diri', 'effective_date' => $effectiveDate]);
    }

    protected function storeCode(Employee $employee, string $date, string $code, AttendanceSource $source = AttendanceSource::ImportExcel): void
    {
        app(AttendanceDayService::class)->write($employee->id, $date, ['status_code' => $code], $source, $source === AttendanceSource::Manual ? 'Koreksi' : null);
    }

    protected function storeOvertime(Employee $employee, string $date, string $start, string $end, string $type = 'reguler', string $source = 'import_excel'): OvertimeEntry
    {
        return OvertimeEntry::create([
            'employee_id' => $employee->id, 'date' => $date, 'start_time' => $start, 'end_time' => $end,
            'duration_minutes' => OvertimeEntry::minutesBetween($start, $end), 'overtime_type' => $type,
            'description' => 'Persiapan acara', 'source' => $source,
        ]);
    }

    protected function template(string $type, string $period = '2026-10'): Spreadsheet
    {
        $response = $this->get("/api/v1/attendance-imports/template?period={$period}&type={$type}")->assertOk();

        return $this->spreadsheetOf($response);
    }

    protected function spreadsheetOf(TestResponse $response): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        return IOFactory::load($path);
    }

    /** Baris template daftar hadir milik karyawan itu (dicari dari kolom ID Sistem). */
    protected function attendanceRow(Worksheet $sheet, Employee $employee): int
    {
        for ($row = AttendanceListLayout::FIRST_DATA_ROW; $row <= $sheet->getHighestRow(); $row++) {
            if ($sheet->getCell("A{$row}")->getValue() === $employee->id) {
                return $row;
            }
        }
        $this->fail("Karyawan {$employee->name} tidak ada di template.");
    }

    /** Isi kode daftar hadir: [tanggal (hari ke-) => kode|null]. */
    protected function setCodes(Spreadsheet $spreadsheet, Employee $employee, array $codes): void
    {
        $sheet = $spreadsheet->getSheetByName(AttendanceListLayout::SHEET_TITLE);
        $row = $this->attendanceRow($sheet, $employee);
        foreach ($codes as $day => $code) {
            $cell = AttendanceListLayout::dateLetter($day).$row;
            $code === null ? $sheet->setCellValue($cell, null) : $sheet->setCellValueExplicit($cell, $code, DataType::TYPE_STRING);
        }
    }

    /** Isi baris lembur ke-$index (0 = baris data pertama): [reg, tanggal "dd/mm/yyyy", mulai, selesai, uraian]. */
    protected function setOvertimeRows(Spreadsheet $spreadsheet, string $type, array $rows): void
    {
        $sheet = $spreadsheet->getSheet(0);
        foreach ($rows as $index => [$registration, $date, $start, $end, $description]) {
            $row = OvertimeLayout::FIRST_DATA_ROW + $index;
            $values = ['registration_number' => $registration, 'date' => $date, 'start_time' => $start, 'end_time' => $end, 'description' => $description];
            foreach ($values as $key => $value) {
                $cell = OvertimeLayout::letter($key).$row;
                $value === null ? $sheet->setCellValue($cell, null) : $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
            }
        }
    }

    protected function upload(Spreadsheet $spreadsheet): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'absensi.xlsx', null, null, true);
    }

    protected function preview(Spreadsheet $spreadsheet, string $type, string $period = '2026-10'): TestResponse
    {
        return $this->postJson('/api/v1/attendance-imports/preview', ['file' => $this->upload($spreadsheet), 'period' => $period, 'type' => $type]);
    }

    protected function import(Spreadsheet $spreadsheet, string $type, string $period = '2026-10'): TestResponse
    {
        return $this->postJson('/api/v1/attendance-imports', ['file' => $this->upload($spreadsheet), 'period' => $period, 'type' => $type]);
    }

    /** Baris pratinjau milik karyawan itu. */
    protected function previewRow(TestResponse $response, Employee $employee): array
    {
        return collect($response->json('data.rows'))->firstWhere('employee_id', $employee->id) ?? $this->fail("Tidak ada baris {$employee->name}");
    }

    /** errors / warnings baris pratinjau sebagai [kolom => pesan]. */
    protected function messages(array $row, string $list = 'errors'): array
    {
        return collect($row[$list])->mapWithKeys(fn (array $item) => [$item['column'] => $item['message']])->all();
    }
}
