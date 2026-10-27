<?php

namespace App\Services\Payroll;

use App\Enums\PayrollPeriodStatus;
use App\Enums\WageType;
use App\Models\PayrollPeriod;
use App\Support\DataPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * ATURAN ISIAN SKEMA UPAH — satu tempat untuk tambah satu karyawan, ubah massal (bulk), dan impor Excel.
 *
 *   Jenis Upah          wajib, Harian / Flat Bulanan
 *   Gaji Pokok          wajib, rupiah > 0
 *   HKE                 1–31, kosong = 25
 *   Dibatasi 20 hari    Ya/Tidak; kalau Ya, jumlah hari kosong = 20; kalau Tidak, jumlah hari disimpan NULL
 *   Tunj. Ops & Jabatan rupiah ≥ 0, kosong = 0
 *   Insentif            rupiah ≥ 0, kosong = 0
 *   Diberi Uang Makan   Ya/Tidak; kalau Ya, Uang Makan per Hari WAJIB > 0; kalau Tidak, disimpan 0
 *   Kelompok Daftar Upah boleh kosong
 *   Berlaku Mulai       tidak boleh ≤ akhir periode upah yang sudah dikunci (lockedPeriodMessage)
 */
class WageSchemeRules
{
    public const DEFAULT_WORKING_DAYS = 25;

    public const DEFAULT_CAP_DAYS = 20;

    public const NOT_DAILY_WAGE_MESSAGE = 'Skema upah hanya untuk karyawan Casual & Daily Worker';

    /** Periode upah terkunci yang paling akhir ("YYYY-MM"), atau null. */
    public static function latestLockedPeriod(): ?string
    {
        return PayrollPeriod::query()->where('status', PayrollPeriodStatus::Locked)->max('period');
    }

    /**
     * Pesan penolakan kalau Berlaku Mulai ≤ akhir periode upah yang sudah dikunci (skema itu akan mengubah
     * upah periode terkunci), atau null kalau boleh.
     */
    public static function lockedPeriodMessage(Carbon|string $effectiveDate, ?string $latestLockedPeriod): ?string
    {
        $month = Carbon::parse($effectiveDate)->format('Y-m');

        return $latestLockedPeriod !== null && $month <= $latestLockedPeriod
            ? 'Berlaku Mulai '.Carbon::parse($effectiveDate)->format('d/m/Y').' mengubah upah periode '.DataPeriod::label($latestLockedPeriod).' yang sudah dikunci'
            : null;
    }

    /**
     * Aturan validasi satu skema lengkap. $values = isian yang dikirim (untuk aturan uang makan).
     *
     * @param  array<string, mixed>  $values
     */
    public static function rules(array $values): array
    {
        return [
            /** Jenis upah: Harian / Flat Bulanan. */
            'wage_type' => ['required', Rule::enum(WageType::class)],
            /** Gaji Pokok (rupiah). */
            'base_salary' => ['required', 'integer', 'min:1'],
            /** HKE (hari kerja efektif), kosong = 25. */
            'working_days' => ['nullable', 'integer', 'between:1,31'],
            /** Dibatasi 20 hari. */
            'is_capped' => ['nullable', 'boolean'],
            /** Jumlah hari batas, kosong = 20 (diabaikan kalau tidak dibatasi). */
            'cap_days' => ['nullable', 'integer', 'between:1,31'],
            /** Tunjangan Operasional & Jabatan (rupiah). */
            'operational_position_allowance' => ['nullable', 'integer', 'min:0'],
            /** Insentif (rupiah). */
            'incentive' => ['nullable', 'integer', 'min:0'],
            /** Diberi uang makan. */
            'has_meal_allowance' => ['nullable', 'boolean'],
            /** Uang makan per hari (rupiah); wajib > 0 kalau diberi uang makan. */
            'meal_allowance_per_day' => self::hasMealAllowance($values)
                ? ['required', 'integer', 'min:1']
                : ['nullable', 'integer'],
            /** Kelompok Daftar Upah, boleh kosong. */
            'payroll_group_id' => ['nullable', 'uuid', 'exists:payroll_groups,id'],
        ];
    }

    public static function messages(): array
    {
        return [
            'wage_type.required' => 'Jenis Upah wajib diisi',
            'wage_type.enum' => 'Jenis Upah harus Harian atau Flat Bulanan',
            'base_salary.required' => 'Gaji Pokok wajib diisi',
            'base_salary.integer' => 'Gaji Pokok harus berupa angka bulat',
            'base_salary.min' => 'Gaji Pokok harus lebih dari 0',
            'working_days.integer' => 'HKE harus berupa angka bulat 1–31',
            'working_days.between' => 'HKE harus 1–31',
            'cap_days.between' => 'Batas hari harus 1–31',
            'operational_position_allowance.integer' => 'Tunjangan Operasional & Jabatan harus berupa angka bulat',
            'operational_position_allowance.min' => 'Tunjangan Operasional & Jabatan tidak boleh negatif',
            'incentive.integer' => 'Insentif harus berupa angka bulat',
            'incentive.min' => 'Insentif tidak boleh negatif',
            'meal_allowance_per_day.required' => 'Uang Makan per Hari wajib diisi kalau Diberi Uang Makan = Ya',
            'meal_allowance_per_day.integer' => 'Uang Makan per Hari harus berupa angka bulat',
            'meal_allowance_per_day.min' => 'Uang Makan per Hari harus lebih dari 0',
            'payroll_group_id.uuid' => 'Kelompok Daftar Upah tidak ditemukan',
            'payroll_group_id.exists' => 'Kelompok Daftar Upah tidak ditemukan',
        ];
    }

    /** Pesan isian "berlaku mulai" (dipakai semua riwayat). */
    public static function effectiveDateMessages(): array
    {
        return [
            'effective_date.required' => 'Berlaku Mulai wajib diisi',
            'effective_date.date_format' => 'Berlaku Mulai harus berformat YYYY-MM-DD',
        ];
    }

    /**
     * Rapikan skema yang SUDAH valid menjadi nilai yang disimpan (bawaan diisi, isian yang tidak berlaku dikosongkan).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function normalize(array $values): array
    {
        $isCapped = filter_var($values['is_capped'] ?? false, FILTER_VALIDATE_BOOL);
        $hasMeal = self::hasMealAllowance($values);
        $wageType = $values['wage_type'];

        return [
            'wage_type' => $wageType instanceof WageType ? $wageType->value : $wageType,
            'base_salary' => (int) $values['base_salary'],
            'working_days' => (int) ($values['working_days'] ?? self::DEFAULT_WORKING_DAYS),
            'is_capped' => $isCapped,
            'cap_days' => $isCapped ? (int) ($values['cap_days'] ?? self::DEFAULT_CAP_DAYS) : null,
            'operational_position_allowance' => (int) ($values['operational_position_allowance'] ?? 0),
            'incentive' => (int) ($values['incentive'] ?? 0),
            'has_meal_allowance' => $hasMeal,
            'meal_allowance_per_day' => $hasMeal ? (int) $values['meal_allowance_per_day'] : 0,
            'payroll_group_id' => $values['payroll_group_id'] ?? null,
        ];
    }

    /** @param  array<string, mixed>  $values */
    private static function hasMealAllowance(array $values): bool
    {
        return filter_var($values['has_meal_allowance'] ?? false, FILTER_VALIDATE_BOOL);
    }
}
