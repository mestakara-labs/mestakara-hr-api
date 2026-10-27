<?php

namespace App\Enums;

/**
 * Status kepegawaian (employees.active_status).
 *
 *   Belum Aktif -> karyawan baru (draf) sampai usulan Penambahan Berlaku & TMT tiba
 *   Aktif       -> setelah Penambahan Berlaku
 *   Nonaktif    -> setelah Penonaktifan Berlaku
 */
enum EmployeeActiveStatus: string
{
    use HasValues;

    case Pending = 'Belum Aktif';
    case Active = 'Aktif';
    case Inactive = 'Nonaktif';
}
