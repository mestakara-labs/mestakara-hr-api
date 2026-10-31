<?php

namespace App\Services\Dashboard;

use App\Enums\EmployeeActiveStatus;
use App\Models\Employee;
use Closure;
use Illuminate\Support\Carbon;

/**
 * FAKTA SATU KARYAWAN PADA SATU TANGGAL (dibuat oleh WorkforceFacts): status kepegawaian & jabatan utama per tanggal
 * itu (aturan sama dengan Periode Data), penempatan, data dasar upah, usia, pendidikan, masa kerja, pensiun.
 *
 * Nilai satu dimensi dibaca lewat values(), selalu berupa daftar [{ key, label }] karena objek penempatan &
 * kategori objek bisa lebih dari satu. Tanpa nilai = key "tanpa" (mis. "Tanpa Bidang").
 */
class EmployeeFacts
{
    public const NONE_KEY = 'tanpa';

    /**
     * @param  array<string, string>  $placementObjects  [id => nama]
     * @param  array<string, string>  $segments  [id kategori => nama kategori]
     * @param  array{id: string, code: string, name: string}|null  $costCenter
     * @param  array{id: string, name: string}|null  $payrollGroup  hanya Casual & DW (dari skema upah)
     */
    private ?int $serviceMonths = null;

    public function __construct(
        public readonly Employee $employee,
        public readonly Carbon $date,
        public readonly EmployeeActiveStatus $status,
        public readonly ?string $divisionId,
        public readonly ?string $divisionName,
        public readonly ?string $subUnitId,
        public readonly ?string $subUnitName,
        public readonly array $placementObjects,
        public readonly array $segments,
        public readonly ?array $costCenter,
        public readonly ?array $payrollGroup,
        public readonly ?int $brmLevel,
        public readonly ?int $jobGrade,
        public readonly ?int $age,
        public readonly string $education,
        private readonly Closure $countServiceMonths,
        public readonly ?Carbon $retirementDate,
        public readonly bool $hasWageScheme,
        public readonly bool $hasBankAccount,
    ) {}

    /** Masa kerja (bulan); dihitung saat pertama diminta karena cukup berat. */
    public function serviceMonths(): int
    {
        return $this->serviceMonths ??= ($this->countServiceMonths)();
    }

    public function isActive(): bool
    {
        return $this->status === EmployeeActiveStatus::Active;
    }

    public function hasDailyWage(): bool
    {
        return $this->employee->hasDailyWage();
    }

    /**
     * Nilai satu dimensi. $payrollRow (baris datar Daftar Upah) dipakai untuk bidang, kelompok, & cost center supaya
     * angka upah persis sama dengan Daftar Upah bulan itu.
     *
     * @return list<array{key: string, label: string}>
     */
    public function values(string $dimension, ?array $payrollRow = null): array
    {
        $one = fn (?string $key, ?string $label) => [['key' => $key ?? self::NONE_KEY, 'label' => $key === null ? Dimensions::noneLabel($dimension) : (string) $label]];
        $many = fn (array $items) => $items === []
            ? [['key' => self::NONE_KEY, 'label' => Dimensions::noneLabel($dimension)]]
            : array_map(fn (string $key, string $label) => ['key' => $key, 'label' => $label], array_keys($items), $items);

        return match ($dimension) {
            'division' => $payrollRow === null ? $one($this->divisionId, $this->divisionName) : $one($payrollRow['division_id'], $payrollRow['division_name']),
            'sub_unit' => $one($this->subUnitId, $this->subUnitName),
            'placement_object' => $many($this->placementObjects),
            'segment' => $many($this->segments),
            'cost_center' => $payrollRow === null
                ? $one($this->costCenter['id'] ?? null, isset($this->costCenter) ? "{$this->costCenter['code']} — {$this->costCenter['name']}" : null)
                : $one($payrollRow['cost_center_id'], "{$payrollRow['cost_center_code']} — {$payrollRow['cost_center_name']}"),
            'payroll_group' => $payrollRow === null
                ? $one($this->payrollGroup['id'] ?? null, $this->payrollGroup['name'] ?? null)
                : $one($payrollRow['payroll_group_id'], $payrollRow['payroll_group_name']),
            'employment_status' => $one($this->employee->employment_status, Dimensions::statusLabel($this->employee->employment_status)),
            'brm_level' => $one($this->brmLevel === null ? null : (string) $this->brmLevel, "BRM-{$this->brmLevel}"),
            'gender' => $one($this->employee->gender, $this->employee->gender),
            'age_group' => $one(Dimensions::ageGroupKey($this->age), Dimensions::ageGroupLabel($this->age)),
            'education' => $one($this->education === '-' ? null : $this->education, $this->education),
            'month' => $one($this->date->format('Y-m'), DashboardPeriod::monthLabel($this->date->format('Y-m'))),
            default => throw new \InvalidArgumentException("Dimensi tidak dikenal: {$dimension}"),
        };
    }

    /** @return list<string> kunci nilai satu dimensi (untuk filter) */
    public function keys(string $dimension, ?array $payrollRow = null): array
    {
        return array_column($this->values($dimension, $payrollRow), 'key');
    }
}
