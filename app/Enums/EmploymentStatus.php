<?php

namespace App\Enums;

/**
 * Status karyawan (employees.employment_status).
 * NIK Karyawan wajib untuk Tetap & PKWT, opsional untuk Casual & Daily Worker.
 */
enum EmploymentStatus: string
{
    use HasValues;

    case Permanent = 'Karyawan Tetap';
    case Contract = 'Karyawan PKWT';
    case Casual = 'Karyawan Casual';
    case DailyWorker = 'Karyawan Daily Worker';

    /** Apakah karyawan dengan status ini wajib punya NIK Karyawan? */
    public function requiresNik(): bool
    {
        return in_array($this, [self::Permanent, self::Contract], true);
    }

    /** Apakah karyawan dengan status ini diupah harian lewat skema upah (Casual & Daily Worker)? */
    public function hasDailyWage(): bool
    {
        return in_array($this, [self::Casual, self::DailyWorker], true);
    }

    /** @return list<string> Status karyawan yang memakai skema upah. */
    public static function dailyWageValues(): array
    {
        return [self::Casual->value, self::DailyWorker->value];
    }
}
