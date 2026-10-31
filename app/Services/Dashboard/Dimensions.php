<?php

namespace App\Services\Dashboard;

use App\Enums\EducationLevel;
use App\Enums\EmploymentStatus;
use App\Enums\Gender;
use App\Models\PayrollGroup;

/**
 * DIMENSI pengelompokan dashboard & analitik: judul, label "tanpa nilai", kelompok usia, dan urutan baku kategori.
 *
 *   sub_unit · division · segment (Kategori Objek) · placement_object · cost_center · payroll_group ·
 *   employment_status · brm_level · gender · age_group · education · month
 *
 * Kelompok usia sama dengan halaman Data Karyawan: < 25, 25–34, 35–44, 45–54, ≥ 55 tahun.
 */
class Dimensions
{
    public const LABELS = [
        'sub_unit' => 'Sub Unit',
        'division' => 'Bidang',
        'segment' => 'Kategori Objek',
        'placement_object' => 'Objek Penempatan',
        'cost_center' => 'Cost Center',
        'payroll_group' => 'Kelompok Daftar Upah',
        'employment_status' => 'Status Karyawan',
        'brm_level' => 'Level BRM',
        'gender' => 'Jenis Kelamin',
        'age_group' => 'Kelompok Usia',
        'education' => 'Pendidikan',
        'month' => 'Bulan',
    ];

    /** Dimensi yang bisa dipakai sebagai filter dashboard / analitik. */
    public const FILTERS = ['sub_unit', 'division', 'segment', 'placement_object', 'cost_center', 'payroll_group', 'employment_status'];

    /** [key => [batas atas usia, label]] */
    public const AGE_GROUPS = [
        'lt25' => [24, '< 25 tahun'],
        '25_34' => [34, '25–34 tahun'],
        '35_44' => [44, '35–44 tahun'],
        '45_54' => [54, '45–54 tahun'],
        'gte55' => [PHP_INT_MAX, '≥ 55 tahun'],
    ];

    private const NONE_LABELS = [
        'sub_unit' => 'Tanpa Sub Unit',
        'division' => 'Tanpa Bidang',
        'segment' => 'Tanpa Kategori Objek',
        'placement_object' => 'Tanpa Objek Penempatan',
        'cost_center' => 'Tanpa Cost Center',
        'payroll_group' => 'Tanpa Kelompok',
        'employment_status' => 'Tanpa Status',
        'brm_level' => 'Belum Ditempatkan',
        'gender' => 'Jenis Kelamin Belum Diisi',
        'age_group' => 'Tanggal Lahir Belum Diisi',
        'education' => 'Belum Ada Riwayat Pendidikan',
        'month' => '-',
    ];

    public static function noneLabel(string $dimension): string
    {
        return self::NONE_LABELS[$dimension];
    }

    /** "Karyawan Daily Worker" -> "Daily Worker". */
    public static function statusLabel(string $status): string
    {
        return str_replace('Karyawan ', '', $status);
    }

    public static function ageGroupKey(?int $age): ?string
    {
        if ($age === null) {
            return null;
        }
        foreach (self::AGE_GROUPS as $key => [$max]) {
            if ($age <= $max) {
                return $key;
            }
        }

        return null;
    }

    public static function ageGroupLabel(?int $age): ?string
    {
        $key = self::ageGroupKey($age);

        return $key === null ? null : self::AGE_GROUPS[$key][1];
    }

    /**
     * Urutkan kategori menurut urutan baku dimensi (status, jenis kelamin, usia, pendidikan, BRM, bulan, urutan
     * Kelompok Daftar Upah);
     * dimensi lain menurut label. "Tanpa …" selalu terakhir.
     *
     * @param  array<string, string>  $categories  [key => label]
     * @return array<string, string>
     */
    public static function sort(string $dimension, array $categories): array
    {
        $order = match ($dimension) {
            'employment_status' => EmploymentStatus::values(),
            'gender' => Gender::values(),
            'age_group' => array_keys(self::AGE_GROUPS),
            'education' => EducationLevel::values(),
            'brm_level' => array_map('strval', range(1, 6)),
            'payroll_group' => PayrollGroup::query()->orderBy('sort_order')->orderBy('name')->pluck('id')->all(),   // urutan Daftar Upah
            default => null,
        };

        uksort($categories, function (string $a, string $b) use ($categories, $order, $dimension) {
            if (($a === EmployeeFacts::NONE_KEY) !== ($b === EmployeeFacts::NONE_KEY)) {
                return $a === EmployeeFacts::NONE_KEY ? 1 : -1;
            }
            if ($dimension === 'month') {
                return strcmp($a, $b);
            }
            if ($order !== null) {
                $rank = fn (string $key) => ($position = array_search($key, $order, true)) === false ? PHP_INT_MAX : $position;

                return $rank($a) <=> $rank($b) ?: strcmp($a, $b);
            }

            return strnatcasecmp($categories[$a], $categories[$b]);
        });

        return $categories;
    }
}
