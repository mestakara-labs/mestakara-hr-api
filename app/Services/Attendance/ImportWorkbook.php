<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceImportType;
use App\Support\DataPeriod;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;

/**
 * Membuka file template absensi / lembur yang diunggah dan memastikan file itu memang template untuk
 * PERIODE & JENIS yang dipilih (dibaca dari sheet tersembunyi "Daftar"). Kalau tidak, seluruh file ditolak 422.
 */
class ImportWorkbook
{
    /**
     * @param  list<string>  $sheets  sheet yang wajib ada (sheet data + sheet "Daftar")
     */
    public static function open(
        UploadedFile $file,
        array $sheets,
        AttendancePeriod $period,
        AttendanceImportType $type,
        string $periodCell,
        string $typeCell,
    ): Spreadsheet {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $names = $reader->listWorksheetNames($file->getRealPath());
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'File Excel tidak bisa dibaca. Pastikan formatnya XLSX.']);
        }

        if (array_diff($sheets, $names) !== []) {
            throw ValidationException::withMessages(['file' => 'File bukan template '.self::typeLabel($type).'. Unduh template terbaru, isi, lalu unggah kembali tanpa mengubah nama sheet.']);
        }

        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly($sheets);
        $spreadsheet = $reader->load($file->getRealPath());

        $list = $spreadsheet->getSheetByName($sheets[array_key_last($sheets)]);
        $filePeriod = trim((string) $list->getCell($periodCell)->getValue());
        $fileType = trim((string) $list->getCell($typeCell)->getValue());

        if ($fileType !== $type->value) {
            $spreadsheet->disconnectWorksheets();
            throw ValidationException::withMessages(['file' => 'File ini bukan template '.self::typeLabel($type).'.']);
        }
        if ($filePeriod !== $period->period) {
            $spreadsheet->disconnectWorksheets();
            $label = preg_match('/^\d{4}-\d{2}$/', $filePeriod) ? DataPeriod::label($filePeriod) : 'lain';
            throw ValidationException::withMessages(['file' => "File ini untuk periode {$label}, bukan {$period->label()}. Unduh template periode {$period->label()}."]);
        }

        return $spreadsheet;
    }

    public static function typeLabel(AttendanceImportType $type): string
    {
        return match ($type) {
            AttendanceImportType::AttendanceList => 'Daftar Hadir',
            AttendanceImportType::RegularOvertime => 'Lembur Reguler',
            AttendanceImportType::DailyWorkerOvertime => 'Lembur DW',
        };
    }

    /** Teks sel (angka bulat tanpa desimal); kosong = null. */
    public static function text(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_float($value) && floor($value) === $value) {
            return sprintf('%.0f', $value);
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
