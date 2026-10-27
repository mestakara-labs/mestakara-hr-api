<?php

namespace App\Enums;

/**
 * Jenjang pendidikan (employee_educations.level).
 * URUTAN case = urutan jenjang: SD paling rendah, S3 paling tinggi.
 */
enum EducationLevel: string
{
    use HasValues;

    case Sd = 'SD';
    case Smp = 'SMP';
    case Sma = 'SMA/SMK';
    case D3 = 'D3';
    case D4 = 'D4';
    case S1 = 'S1';
    case S2 = 'S2';
    case S3 = 'S3';

    /** Peringkat jenjang: SD = 0 … S3 = 7. Jenjang tidak dikenal = -1. */
    public static function rank(string $level): int
    {
        $index = array_search($level, self::values(), true);

        return $index === false ? -1 : $index;
    }
}
