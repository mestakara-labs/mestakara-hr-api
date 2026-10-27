<?php

namespace App\Services\Payroll;

/**
 * DAFTAR KOLOM template impor Skema Upah — dipakai bersama oleh penulis template (WageSchemeTemplateWorkbook)
 * dan pembaca file unggahan (WageSchemeImportReader). Mengubah urutan / judul kolom cukup di sini.
 *
 * group:
 *   id     ID Sistem (tersembunyi, dikunci) — kunci pencocokan karyawan
 *   info   hanya informasi, dikunci (judul diberi tanda *)
 *   wage   skema upah: hanya untuk Casual & Daily Worker (dikunci & abu-abu di baris Tetap / PKWT)
 *   edit   boleh diisi untuk semua karyawan (rekening, cost center, berlaku mulai, keterangan)
 *
 * format: text (sel teks, angka tidak berubah), money (#,##0), number, date (dd/mm/yyyy), atau null.
 * list:   nama daftar pilihan (dropdown) di sheet "Daftar", atau null.
 */
class WageSchemeTemplateColumns
{
    public const SHEET_TITLE = 'Skema Upah';

    public const LIST_SHEET_TITLE = 'Daftar';

    public const GUIDE_SHEET_TITLE = 'Petunjuk';

    public const HEADER_ROW = 1;

    public const FIRST_DATA_ROW = 2;

    /** Pilihan Ya/Tidak. */
    public const YES = 'Ya';

    public const NO = 'Tidak';

    /** @var list<array{key: string, letter: string, header: string, width: int, group: string, format: ?string, list: ?string}> */
    public const COLUMNS = [
        ['key' => 'employee_id', 'letter' => 'A', 'header' => 'ID Sistem', 'width' => 38, 'group' => 'id', 'format' => 'text', 'list' => null],
        ['key' => 'registration_number', 'letter' => 'B', 'header' => 'No. Registrasi', 'width' => 18, 'group' => 'edit', 'format' => 'text', 'list' => null],
        ['key' => 'name', 'letter' => 'C', 'header' => 'Nama*', 'width' => 32, 'group' => 'info', 'format' => null, 'list' => null],
        ['key' => 'employment_status', 'letter' => 'D', 'header' => 'Status*', 'width' => 22, 'group' => 'info', 'format' => null, 'list' => null],
        ['key' => 'sub_unit', 'letter' => 'E', 'header' => 'Sub Unit*', 'width' => 24, 'group' => 'info', 'format' => null, 'list' => null],
        ['key' => 'wage_type', 'letter' => 'F', 'header' => 'Jenis Upah', 'width' => 15, 'group' => 'wage', 'format' => null, 'list' => 'wage_types'],
        ['key' => 'base_salary', 'letter' => 'G', 'header' => 'Gaji Pokok', 'width' => 15, 'group' => 'wage', 'format' => 'money', 'list' => null],
        ['key' => 'working_days', 'letter' => 'H', 'header' => 'HKE', 'width' => 8, 'group' => 'wage', 'format' => 'number', 'list' => null],
        ['key' => 'is_capped', 'letter' => 'I', 'header' => 'Dibatasi 20 Hari', 'width' => 12, 'group' => 'wage', 'format' => null, 'list' => 'yes_no'],
        ['key' => 'operational_position_allowance', 'letter' => 'J', 'header' => 'Tunjangan Operasional & Jabatan', 'width' => 17, 'group' => 'wage', 'format' => 'money', 'list' => null],
        ['key' => 'incentive', 'letter' => 'K', 'header' => 'Insentif', 'width' => 13, 'group' => 'wage', 'format' => 'money', 'list' => null],
        ['key' => 'has_meal_allowance', 'letter' => 'L', 'header' => 'Diberi Uang Makan', 'width' => 12, 'group' => 'wage', 'format' => null, 'list' => 'yes_no'],
        ['key' => 'meal_allowance_per_day', 'letter' => 'M', 'header' => 'Uang Makan per Hari', 'width' => 14, 'group' => 'wage', 'format' => 'money', 'list' => null],
        ['key' => 'payroll_group', 'letter' => 'N', 'header' => 'Kelompok Daftar Upah', 'width' => 22, 'group' => 'wage', 'format' => null, 'list' => 'payroll_groups'],
        ['key' => 'bank_name', 'letter' => 'O', 'header' => 'Nama Bank', 'width' => 15, 'group' => 'edit', 'format' => null, 'list' => null],
        ['key' => 'account_number', 'letter' => 'P', 'header' => 'No. Rekening', 'width' => 22, 'group' => 'edit', 'format' => 'text', 'list' => null],
        ['key' => 'account_holder', 'letter' => 'Q', 'header' => 'Atas Nama', 'width' => 30, 'group' => 'edit', 'format' => null, 'list' => null],
        ['key' => 'cost_center_code', 'letter' => 'R', 'header' => 'Kode Cost Center', 'width' => 17, 'group' => 'edit', 'format' => null, 'list' => 'cost_centers'],
        ['key' => 'effective_date', 'letter' => 'S', 'header' => 'Berlaku Mulai', 'width' => 14, 'group' => 'edit', 'format' => 'date', 'list' => null],
        ['key' => 'notes', 'letter' => 'T', 'header' => 'Keterangan', 'width' => 30, 'group' => 'edit', 'format' => null, 'list' => null],
    ];

    /** Kolom skema upah (group wage). */
    public const WAGE_KEYS = [
        'wage_type', 'base_salary', 'working_days', 'is_capped', 'operational_position_allowance',
        'incentive', 'has_meal_allowance', 'meal_allowance_per_day', 'payroll_group',
    ];

    /** Kolom rekening bank (harus diisi lengkap bertiga). */
    public const BANK_KEYS = ['bank_name', 'account_number', 'account_holder'];

    /** Judul kolom dari kuncinya, mis. "base_salary" -> "Gaji Pokok". */
    public static function header(string $key): string
    {
        return self::byKey()[$key]['header'];
    }

    /** Huruf kolom dari kuncinya, mis. "base_salary" -> "G". */
    public static function letter(string $key): string
    {
        return self::byKey()[$key]['letter'];
    }

    public static function lastLetter(): string
    {
        return self::COLUMNS[array_key_last(self::COLUMNS)]['letter'];
    }

    /** @return array<string, array{key: string, letter: string, header: string, width: int, group: string, format: ?string, list: ?string}> */
    public static function byKey(): array
    {
        return array_column(self::COLUMNS, null, 'key');
    }
}
