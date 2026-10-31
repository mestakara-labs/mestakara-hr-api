<?php

namespace App\Services\Dashboard;

use App\Models\CostCenter;
use App\Models\Division;
use App\Models\PayrollGroup;
use App\Models\PlacementCategory;
use App\Models\PlacementObject;
use App\Models\SubUnit;

/**
 * FILTER DIMENSI dashboard & analitik: filters[sub_unit][]=id, filters[division][]=id, filters[segment][]=id,
 * filters[placement_object][]=id, filters[cost_center][]=id, filters[payroll_group][]=id,
 * filters[employment_status][]=teks. Beberapa nilai dalam satu dimensi = "atau"; antar-dimensi = "dan".
 * Karyawan cocok dengan objek penempatan / kategori objek bila SALAH SATU objeknya cocok.
 * Nilai "tanpa" = karyawan tanpa nilai dimensi itu (mis. tanpa bidang).
 */
class DashboardFilters
{
    /** @param  array<string, list<string>>  $values  hanya dimensi yang diisi */
    private function __construct(private array $values) {}

    /** @param  array<string, mixed>|null  $input  isian filters yang sudah divalidasi */
    public static function make(?array $input): self
    {
        $values = [];
        foreach (Dimensions::FILTERS as $dimension) {
            $selected = array_values(array_filter(array_map('strval', (array) ($input[$dimension] ?? [])), fn (string $value) => $value !== ''));
            if ($selected !== []) {
                $values[$dimension] = $selected;
            }
        }

        return new self($values);
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /** Karyawan (dan baris Daftar Upahnya, kalau ada) memenuhi semua filter? */
    public function matches(EmployeeFacts $facts, ?array $payrollRow = null): bool
    {
        foreach ($this->values as $dimension => $selected) {
            if (array_intersect($facts->keys($dimension, $payrollRow), $selected) === []) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, list<string>> untuk respons widget */
    public function toArray(): array
    {
        return $this->values;
    }

    /** Uraian filter untuk dokumen, mis. "Bidang: Wahana, Tanpa Bidang; Status Karyawan: Casual". */
    public function describe(): string
    {
        if ($this->isEmpty()) {
            return 'Tanpa filter (semua karyawan)';
        }

        $parts = [];
        foreach ($this->values as $dimension => $selected) {
            $names = match ($dimension) {
                'sub_unit' => SubUnit::query()->whereIn('id', $selected)->pluck('name', 'id')->all(),
                'division' => Division::query()->whereIn('id', $selected)->pluck('name', 'id')->all(),
                'segment' => PlacementCategory::query()->whereIn('id', $selected)->pluck('name', 'id')->all(),
                'placement_object' => PlacementObject::query()->whereIn('id', $selected)->pluck('name', 'id')->all(),
                'cost_center' => CostCenter::query()->whereIn('id', $selected)->get()
                    ->mapWithKeys(fn (CostCenter $costCenter) => [$costCenter->id => "{$costCenter->code} — {$costCenter->name}"])->all(),
                'payroll_group' => PayrollGroup::query()->whereIn('id', $selected)->pluck('name', 'id')->all(),
                'employment_status' => array_combine($selected, array_map(fn (string $status) => Dimensions::statusLabel($status), $selected)),
            };
            $labels = array_map(
                fn (string $value) => $value === EmployeeFacts::NONE_KEY ? Dimensions::noneLabel($dimension) : ($names[$value] ?? $value),
                $selected,
            );
            $parts[] = Dimensions::LABELS[$dimension].': '.implode(', ', $labels);
        }

        return implode('; ', $parts);
    }
}
