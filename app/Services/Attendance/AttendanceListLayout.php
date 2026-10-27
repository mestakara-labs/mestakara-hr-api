<?php

namespace App\Services\Attendance;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * TATA LETAK template DAFTAR HADIR — dipakai bersama oleh penulis template (AttendanceListTemplateWorkbook)
 * dan pembaca file unggahan (AttendanceListImportReader):
 *
 *   baris 1   judul ("DAFTAR HADIR KARYAWAN CASUAL & DAILY WORKER — UNIT AGROWISATA — Periode: Oktober 2026")
 *   baris 2   judul kolom + nomor tanggal
 *   baris 3   nama hari singkat (Sen, Sel, …)
 *   baris 4   baris filter (kosong, abu-abu; tombol filter & urut Excel ada di baris ini)
 *   baris 5…  satu karyawan per baris
 *
 *   A ID Sistem (tersembunyi) | B No | C No. Registrasi | D Nama | E Jabatan | F Status | G Sub Unit | H Kode Afd |
 *   I Objek Penempatan | J Kelompok Upah | K Cost Center | L Uraian Cost Center |
 *   M… tanggal 1..akhir bulan | jumlah K, DW, I, S, M, C, L (rumus COUNTIF)
 *
 * Sheet tersembunyi "Daftar": kolom A = daftar kode (dropdown), E1 = periode file, E2 = jenis file.
 */
class AttendanceListLayout
{
    public const SHEET_TITLE = 'Daftar Hadir';

    public const LIST_SHEET_TITLE = 'Daftar';

    public const GUIDE_SHEET_TITLE = 'Petunjuk';

    public const TITLE_ROW = 1;

    public const HEADER_ROW = 2;

    public const DAY_NAME_ROW = 3;

    public const FILTER_ROW = 4;

    public const FIRST_DATA_ROW = 5;

    /** Sel periode & jenis file di sheet "Daftar". */
    public const PERIOD_CELL = 'E1';

    public const TYPE_CELL = 'E2';

    /** Kolom identitas: [kunci => [huruf, judul, lebar]]. */
    public const IDENTITY_COLUMNS = [
        'employee_id' => ['A', 'ID Sistem', 38],
        'no' => ['B', 'No', 5],
        'registration_number' => ['C', 'No. Registrasi', 14],
        'name' => ['D', 'Nama', 30],
        'position' => ['E', 'Jabatan', 28],
        'employment_status' => ['F', 'Status', 20],
        'sub_unit' => ['G', 'Sub Unit', 20],
        'afdeling_code' => ['H', 'Kode Afd', 9],
        'placement_objects' => ['I', 'Objek Penempatan', 22],
        'payroll_group' => ['J', 'Kelompok Upah', 18],
        'cost_center_code' => ['K', 'Cost Center', 13],
        'cost_center_name' => ['L', 'Uraian Cost Center', 22],
    ];

    /** Kolom terakhir yang dibekukan (freeze) bersama judul: sampai Jabatan. */
    public const LAST_FROZEN_COLUMN = 'E';

    /** Nomor kolom (1 = A) tanggal 1. */
    public const FIRST_DATE_COLUMN = 13;

    /** Huruf kolom untuk tanggal ke-$day (1-based). */
    public static function dateLetter(int $day): string
    {
        return Coordinate::stringFromColumnIndex(self::FIRST_DATE_COLUMN + $day - 1);
    }

    /** Huruf kolom jumlah kode ke-$index (0 = K) sesudah $daysInMonth kolom tanggal. */
    public static function countLetter(int $daysInMonth, int $index): string
    {
        return Coordinate::stringFromColumnIndex(self::FIRST_DATE_COLUMN + $daysInMonth + $index);
    }
}
