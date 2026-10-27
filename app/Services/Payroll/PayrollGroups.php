<?php

namespace App\Services\Payroll;

use App\Enums\PayrollGroupBy;
use App\Enums\SignatureDocument;
use App\Enums\SignatureOwnerType;
use App\Models\PayrollGroup;

/**
 * PENGELOMPOKAN daftar upah:
 *
 *   payroll_group (bawaan)  Kelompok Daftar Upah dari skema; tanpa kelompok -> kunci "tanpa", "Tanpa Kelompok"
 *   division                Bidang dari jabatan utama;     tanpa bidang   -> kunci "tanpa", "Tanpa Bidang"
 *
 * Urutan: kelompok menurut sort_order lalu nama; bidang menurut nama; "Tanpa …" selalu terakhir.
 */
class PayrollGroups
{
    public const NONE_KEY = 'tanpa';

    /** Kolom angka yang dijumlahkan per kelompok (totals). */
    public const TOTAL_COLUMNS = [
        'hke', 'present_days', 'base_pay', 'overtime_pay', 'operational_allowance', 'incentive',
        'meal_allowance', 'gross', 'deductions', 'net',
    ];

    /** Kunci kelompok satu baris datar. */
    public static function keyOf(array $row, PayrollGroupBy $groupBy): string
    {
        $id = $groupBy === PayrollGroupBy::PayrollGroup ? $row['payroll_group_id'] : $row['division_id'];

        return $id ?? self::NONE_KEY;
    }

    public static function noneName(PayrollGroupBy $groupBy): string
    {
        return $groupBy === PayrollGroupBy::PayrollGroup ? 'Tanpa Kelompok' : 'Tanpa Bidang';
    }

    public static function ownerType(PayrollGroupBy $groupBy): SignatureOwnerType
    {
        return $groupBy === PayrollGroupBy::PayrollGroup ? SignatureOwnerType::PayrollGroup : SignatureOwnerType::Division;
    }

    /**
     * Kelompok berurutan beserta barisnya.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return list<array{key: string, owner_id: string|null, name: string, sub_units: list<array{name: string, afdeling_code: string|null}>, employee_count: int, signature: array, totals: array<string, int>, rows: list<array<string, mixed>>}>
     */
    public static function build(array $rows, PayrollGroupBy $groupBy, SignatureBook $signatures): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $buckets[self::keyOf($row, $groupBy)][] = $row;
        }

        $sortOrders = $groupBy === PayrollGroupBy::PayrollGroup ? PayrollGroup::query()->pluck('sort_order', 'id')->all() : [];
        $nameColumn = $groupBy === PayrollGroupBy::PayrollGroup ? 'payroll_group_name' : 'division_name';
        $groups = [];

        foreach ($buckets as $key => $groupRows) {
            $ownerId = $key === self::NONE_KEY ? null : $key;
            $payroll = $signatures->resolve(self::ownerType($groupBy), $ownerId, SignatureDocument::Payroll);
            $spkl = $signatures->resolve(self::ownerType($groupBy), $ownerId, SignatureDocument::Spkl);

            $groups[] = [
                'key' => $key,
                'owner_id' => $ownerId,
                'name' => $ownerId === null ? self::noneName($groupBy) : $groupRows[0][$nameColumn],
                'sub_units' => self::subUnits($groupRows),
                'employee_count' => count($groupRows),
                'signature' => [
                    'place' => $payroll['place'],
                    'uses_general_payroll' => $payroll['inherited'],
                    'uses_general_spkl' => $spkl['inherited'],
                ],
                'totals' => self::totals($groupRows),
                'rows' => $groupRows,
                'sort' => [$ownerId === null ? 1 : 0, $sortOrders[$key] ?? 0, mb_strtolower($ownerId === null ? '' : $groupRows[0][$nameColumn])],
            ];
        }

        usort($groups, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);

        return array_map(function (array $group) {
            unset($group['sort']);

            return $group;
        }, $groups);
    }

    /**
     * Jumlah kolom angka beberapa baris.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public static function totals(iterable $rows): array
    {
        $totals = array_fill_keys(self::TOTAL_COLUMNS, 0);

        foreach ($rows as $row) {
            foreach (self::TOTAL_COLUMNS as $column) {
                $totals[$column] += $column === 'deductions' ? $row['deductions_total'] : $row[$column];
            }
        }

        return $totals;
    }

    /**
     * Sub unit (nama + kode afdeling) yang ada di kelompok, urut nama, tanpa duplikat.
     *
     * @return list<array{name: string, afdeling_code: string|null}>
     */
    private static function subUnits(array $rows): array
    {
        $subUnits = [];
        foreach ($rows as $row) {
            if ($row['sub_unit_name'] !== null) {
                $subUnits[$row['sub_unit_name']] = ['name' => $row['sub_unit_name'], 'afdeling_code' => $row['afdeling_code']];
            }
        }
        ksort($subUnits);

        return array_values($subUnits);
    }
}
