<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Carbon;

/**
 * PERIODE DATA "YYYY-MM" (filter "Periode Data" di halaman Data Karyawan) dan TANGGAL ACUAN-nya:
 *
 *   tanpa periode            -> null (endpoint bekerja seperti biasa, per hari ini)
 *   bulan berjalan (2026-10) -> hari ini
 *   bulan lalu     (2026-09) -> tanggal terakhir bulan itu (2026-09-30; 2024-02 -> 2024-02-29)
 *   bulan depan / format salah -> ditolak oleh rules() (422)
 *
 * "Hari ini" dan "bulan berjalan" memakai waktu kantor (WIB).
 */
class DataPeriod
{
    public const TIMEZONE = 'Asia/Jakarta';

    private const MONTH_NAMES = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    /** Aturan validasi isian `period` (pakai di Form Request). */
    public static function rules(): array
    {
        return [
            'nullable',
            'string',
            'regex:/^\d{4}-(0[1-9]|1[0-2])$/',
            function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && $value > self::today()->format('Y-m')) {
                    $fail('Periode Data tidak boleh melewati bulan berjalan');
                }
            },
        ];
    }

    /** Pesan validasi isian `period`. */
    public static function messages(): array
    {
        return [
            'period.regex' => 'Periode Data harus berformat YYYY-MM, mis. 2026-09',
        ];
    }

    /** Tanggal acuan periode (lihat contoh di atas). null = tanpa periode. */
    public static function asOf(?string $period): ?Carbon
    {
        if ($period === null) {
            return null;
        }

        $today = self::today();
        $date = $period === $today->format('Y-m')
            ? $today->toDateString()
            : Carbon::parse($period.'-01')->endOfMonth()->toDateString();

        // Dibuat ulang di zona waktu aplikasi supaya bisa dibandingkan langsung dengan TMT di database.
        return Carbon::parse($date);
    }

    /** Sama dengan asOf(), tetapi tanpa periode = hari ini. */
    public static function asOfOrToday(?string $period): Carbon
    {
        return self::asOf($period ?? self::today()->format('Y-m'));
    }

    /** Nama bulan & tahun periode, mis. "September 2026". Tanpa periode = bulan berjalan. */
    public static function label(?string $period): string
    {
        $date = $period === null ? self::today() : Carbon::parse($period.'-01');

        return self::MONTH_NAMES[$date->month].' '.$date->year;
    }

    /** Hari ini menurut waktu kantor (WIB). */
    public static function today(): Carbon
    {
        return Carbon::now(self::TIMEZONE)->startOfDay();
    }
}
