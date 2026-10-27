<?php

namespace App\Enums;

/**
 * Sumber data satu hari absensi (attendance_days.source). Kalau dua sumber berbeda untuk tanggal yang sama,
 * yang PRIORITASNYA lebih tinggi menang:  manual (koreksi admin) > mobile > import_excel.
 */
enum AttendanceSource: string
{
    use HasValues;

    case ImportExcel = 'import_excel';
    case Mobile = 'mobile';
    case Manual = 'manual';

    /** Angka prioritas: makin besar makin kuat. */
    public function priority(): int
    {
        return match ($this) {
            self::ImportExcel => 1,
            self::Mobile => 2,
            self::Manual => 3,
        };
    }
}
