<?php

namespace App\Services\Dashboard;

use App\Enums\EmployeeActiveStatus;
use App\Models\Employee;
use App\Models\PositionAssignment;
use App\Services\EmployeeStatusService;
use Closure;
use Illuminate\Support\Carbon;

/**
 * FAKTA SATU KARYAWAN PADA SATU TANGGAL (dibuat oleh WorkforceFacts): status kepegawaian & jabatan utama per tanggal
 * itu (aturan sama dengan Periode Data), penempatan, data dasar upah, usia, pendidikan, masa kerja, pensiun.
 *
 * Setiap fakta baru DIHITUNG saat pertama dibaca lalu disimpan, karena dashboard membuat fakta untuk ratusan karyawan
 * × banyak tanggal sementara satu widget biasanya hanya memakai satu-dua fakta (mis. filter Sub Unit).
 *
 * Nilai satu dimensi dibaca lewat values(), selalu berupa daftar [{ key, label }] karena objek penempatan &
 * kategori objek bisa lebih dari satu. Tanpa nilai = key "tanpa" (mis. "Tanpa Bidang").
 */
class EmployeeFacts
{
    public const NONE_KEY = 'tanpa';

    /** @var array<string, mixed> fakta yang sudah dihitung, lihat remember() */
    private array $remembered = [];

    public function __construct(
        public readonly Employee $employee,
        public readonly Carbon $date,
        private readonly EmployeeStatusService $statusService,
    ) {}

    public EmployeeActiveStatus $status {
        get => $this->remember('status', fn () => $this->statusService->statusOn($this->employee, $this->date));
    }

    public ?string $divisionId {
        get => $this->mainAssignment()?->position->division?->id;
    }

    public ?string $divisionName {
        get => $this->mainAssignment()?->position->division?->name;
    }

    public ?string $subUnitId {
        get => $this->mainAssignment()?->subUnit?->id;
    }

    public ?string $subUnitName {
        get => $this->mainAssignment()?->subUnit?->name;
    }

    /** @var array<string, string> [id => nama] */
    public array $placementObjects {
        get => $this->remember('placementObjects', function () {
            $objects = [];
            foreach ($this->mainAssignment()?->placementObjects ?? [] as $object) {
                $objects[$object->id] = $object->name;
            }

            return $objects;
        });
    }

    /** @var array<string, string> [id kategori => nama kategori] */
    public array $segments {
        get => $this->remember('segments', function () {
            $segments = [];
            foreach ($this->mainAssignment()?->placementObjects ?? [] as $object) {
                if ($object->category !== null) {
                    $segments[$object->category->id] = $object->category->name;
                }
            }

            return $segments;
        });
    }

    /** @var array{id: string, code: string, name: string}|null */
    public ?array $costCenter {
        get => $this->remember('costCenter', function () {
            $costCenter = $this->employee->costCenterOn($this->date)?->costCenter;

            return $costCenter === null ? null : ['id' => $costCenter->id, 'code' => $costCenter->code, 'name' => $costCenter->name];
        });
    }

    /** @var array{id: string, name: string}|null hanya Casual & DW (dari skema upah) */
    public ?array $payrollGroup {
        get => $this->remember('payrollGroup', function () {
            $group = $this->employee->hasDailyWage() ? $this->employee->wageSchemeOn($this->date)?->payrollGroup : null;

            return $group === null ? null : ['id' => $group->id, 'name' => $group->name];
        });
    }

    public ?int $brmLevel {
        get => $this->mainAssignment()?->position->brm_level;
    }

    public ?int $jobGrade {
        get => $this->mainAssignment()?->position->job_grade;
    }

    /** Usia (tahun penuh) pada tanggal itu; null bila tanggal lahir kosong. */
    public ?int $age {
        get => $this->remember('age', function () {
            $birthDate = $this->employee->birth_date;
            if ($birthDate === null || $birthDate->greaterThan($this->date)) {
                return null;
            }
            // Selisih tahun, dikurangi 1 bila ulang tahun tahun itu belum tiba (lebih cepat dari diffInYears).
            $age = $this->date->year - $birthDate->year;

            return $this->date->format('md') < $birthDate->format('md') ? $age - 1 : $age;
        });
    }

    /** Pendidikan terakhir, "-" bila belum ada riwayat. */
    public string $education {
        get => $this->remember('education', fn () => $this->employee->highestEducation()?->level ?? '-');
    }

    /** Masa kerja dalam bulan (tanpa masa nonaktif). */
    public int $serviceMonths {
        get => $this->remember('serviceMonths', fn () => $this->statusService->serviceMonths($this->employee, $this->date));
    }

    public ?Carbon $retirementDate {
        get => $this->remember('retirementDate', fn () => $this->employee->retirementDate($this->mainAssignment()));
    }

    public bool $hasWageScheme {
        get => $this->remember('hasWageScheme', fn () => $this->employee->wageSchemeOn($this->date) !== null);
    }

    public bool $hasBankAccount {
        get => $this->remember('hasBankAccount', fn () => $this->employee->bankAccountOn($this->date) !== null);
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

    /** Jabatan utama pada tanggal itu. */
    private function mainAssignment(): ?PositionAssignment
    {
        return $this->remember('mainAssignment', fn () => $this->employee->mainAssignment($this->date));
    }

    /** Hitung sekali, simpan, lalu pakai ulang (null juga disimpan). */
    private function remember(string $fact, Closure $compute): mixed
    {
        if (! array_key_exists($fact, $this->remembered)) {
            $this->remembered[$fact] = $compute();
        }

        return $this->remembered[$fact];
    }
}
