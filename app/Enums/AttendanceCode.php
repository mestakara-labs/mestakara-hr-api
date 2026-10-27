<?php

namespace App\Enums;

/**
 * Kode absensi harian (attendance_days.status_code).
 * Hanya K yang dibayar & dihitung uang makan. Tanggal tanpa baris = tidak masuk, tidak dibayar.
 */
enum AttendanceCode: string
{
    use HasValues;

    case Work = 'K';
    /** Alat bantu admin, tidak dihitung */
    case DailyWorker = 'DW';
    case Permission = 'I';
    case Sick = 'S';
    case Absent = 'M';
    case Leave = 'C';
    case DayOff = 'L';

    public function label(): string
    {
        return match ($this) {
            self::Work => 'Kerja',
            self::DailyWorker => 'Daily Worker',
            self::Permission => 'Izin',
            self::Sick => 'Sakit',
            self::Absent => 'Mangkir',
            self::Leave => 'Cuti',
            self::DayOff => 'Libur',
        };
    }

    /** Hari dengan kode ini dibayar (dan dihitung uang makan)? */
    public function isPaid(): bool
    {
        return $this === self::Work;
    }
}
