<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceImportType;

/**
 * TATA LETAK template LEMBUR REGULER / LEMBUR DW — dipakai bersama oleh OvertimeTemplateWorkbook dan
 * OvertimeImportReader. Kolom identitasnya SAMA dengan template Daftar Hadir (AttendanceListLayout):
 *
 *   baris 1     judul
 *   baris 2–3   judul kolom
 *   baris 4     baris filter (abu-abu, berisi judul kolom; tombol filter & urut Excel ada di baris ini)
 *   baris 5…    satu lembur per baris, lalu baris kosong berumus untuk lembur baru
 *
 *   A No | B No. Registrasi | C Nama* | D Jabatan* | E Status* | F Sub Unit* | G Kode Afd* | H Objek Penempatan* |
 *   I Kelompok Upah* | J Cost Center* | K Uraian Cost Center* | L Tanggal | M Jam Mulai | N Jam Selesai |
 *   O Durasi* | P Uraian Pekerjaan
 *
 *   (* terisi otomatis: VLOOKUP No. Registrasi ke sheet tersembunyi "Data Karyawan", atau rumus durasi)
 *
 * Hanya karyawan Casual & DW aktif yang PUNYA No. Registrasi yang bisa diisi (kunci pencocokan).
 * Sheet tersembunyi "Daftar": B1 = periode file, B2 = jenis file.
 */
class OvertimeLayout
{
    public const DATA_SHEET_TITLE = 'Data Karyawan';

    public const LIST_SHEET_TITLE = 'Daftar';

    public const GUIDE_SHEET_TITLE = 'Petunjuk';

    public const TITLE_ROW = 1;

    public const HEADER_ROW = 2;

    public const HEADER_END_ROW = 3;

    public const FILTER_ROW = 4;

    public const FIRST_DATA_ROW = 5;

    /** Baris kosong (berumus) yang disiapkan untuk lembur baru. */
    public const BLANK_ROWS = 200;

    /** Kolom terakhir yang dibekukan (freeze) bersama judul: sampai Jabatan. */
    public const LAST_FROZEN_COLUMN = 'D';

    public const PERIOD_CELL = 'B1';

    public const TYPE_CELL = 'B2';

    /**
     * [kunci => [huruf, judul, lebar, kolom ke- di sheet "Data Karyawan" (VLOOKUP) atau null]].
     * Sheet "Data Karyawan": kolom 1 = No. Registrasi, lalu nilai identitas sesuai urutan di bawah.
     */
    public const COLUMNS = [
        'no' => ['A', 'No', 5, null],
        'registration_number' => ['B', 'No. Registrasi', 14, null],
        'name' => ['C', 'Nama*', 30, 2],
        'position' => ['D', 'Jabatan*', 28, 3],
        'employment_status' => ['E', 'Status*', 20, 4],
        'sub_unit' => ['F', 'Sub Unit*', 20, 5],
        'afdeling_code' => ['G', 'Kode Afd*', 9, 6],
        'placement_objects' => ['H', 'Objek Penempatan*', 22, 7],
        'payroll_group' => ['I', 'Kelompok Upah*', 18, 8],
        'cost_center_code' => ['J', 'Cost Center*', 13, 9],
        'cost_center_name' => ['K', 'Uraian Cost Center*', 22, 10],
        'date' => ['L', 'Tanggal', 12, null],
        'start_time' => ['M', 'Jam Mulai', 10, null],
        'end_time' => ['N', 'Jam Selesai', 10, null],
        'duration' => ['O', 'Durasi*', 9, null],
        'description' => ['P', 'Uraian Pekerjaan', 45, null],
    ];

    /** Nama sheet data per jenis lembur. */
    public static function sheetTitle(AttendanceImportType $type): string
    {
        return $type === AttendanceImportType::DailyWorkerOvertime ? 'Lembur DW' : 'Lembur Reguler';
    }

    public static function letter(string $key): string
    {
        return self::COLUMNS[$key][0];
    }

    public static function lastLetter(): string
    {
        return self::COLUMNS[array_key_last(self::COLUMNS)][0];
    }
}
