<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Membaca nilai TANGGAL & JAM dari sel Excel yang diunggah (bisa berupa angka Excel, objek tanggal, atau teks).
 *
 *   ExcelValue::date(46296)          -> Carbon 2026-10-01      ExcelValue::date('01/10/2026') -> Carbon 2026-10-01
 *   ExcelValue::time(0.75)           -> "18:00"                ExcelValue::time('17.30')      -> "17:30"
 *
 * Hasil null = sel kosong, false = isinya tidak terbaca.
 */
class ExcelValue
{
    public static function date(mixed $value): Carbon|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return Carbon::parse($value->format('Y-m-d'));
        }
        if (is_numeric($value)) {
            try {
                return Carbon::parse(ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d'));
            } catch (Throwable) {
                return false;
            }
        }

        $text = trim((string) $value);
        foreach (['d/m/Y', 'j/n/Y', 'Y-m-d', 'd-m-Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $text);
            } catch (Throwable) {
                continue;
            }
            if ($date !== null && $date->format($format) === $text) {
                return $date;
            }
        }

        return false;
    }

    /** Jam "HH:MM" dari pecahan hari Excel, objek waktu, atau teks "17:00" / "17.00". */
    public static function time(mixed $value): string|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i');
        }
        if (is_numeric($value) && (float) $value >= 1 && (float) $value < 24 && floor((float) $value) === (float) $value) {
            return sprintf('%02d:00', (int) $value);   // diketik angka jam saja, mis. 17
        }
        if (is_numeric($value)) {
            $fraction = fmod((float) $value, 1.0);
            if ($fraction < 0) {
                return false;
            }
            $minutes = (int) round($fraction * 1440) % 1440;

            return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }

        if (preg_match('/^(\d{1,2})[.:](\d{2})$/', trim((string) $value), $match) && (int) $match[1] < 24 && (int) $match[2] < 60) {
            return sprintf('%02d:%02d', (int) $match[1], (int) $match[2]);
        }

        return false;
    }
}
