<?php

namespace App\Services\Dashboard;

/**
 * PENJUMLAHAN PER DIMENSI untuk widget & analitik. Satu "item" = { facts: EmployeeFacts, row?: baris Daftar Upah }.
 *
 *   jumlah nilai   ->  $value mengembalikan angka (mis. upah diterima)
 *   hitung orang   ->  $distinct = true, $value mengembalikan id karyawan (orang yang sama dihitung sekali per kelompok)
 *
 * Objek penempatan & kategori objek bisa lebih dari satu per karyawan; karyawan dihitung di SETIAP nilainya, jadi
 * jumlah antar-kelompok dimensi itu bisa lebih besar dari total.
 */
class Aggregate
{
    /**
     * @param  iterable<array{facts: EmployeeFacts, row?: array<string, mixed>|null}>  $items
     * @return array<string, array{label: string, value: int|float}> urut baku dimensi
     */
    public static function by(iterable $items, string $dimension, callable $value, bool $distinct = false): array
    {
        $labels = [];
        $totals = [];
        foreach ($items as $item) {
            $itemValue = $value($item);
            foreach ($item['facts']->values($dimension, $item['row'] ?? null) as ['key' => $key, 'label' => $label]) {
                $labels[$key] = $label;
                if ($distinct) {
                    $totals[$key][$itemValue] = true;
                } else {
                    $totals[$key] = ($totals[$key] ?? 0) + $itemValue;
                }
            }
        }

        $result = [];
        foreach (Dimensions::sort($dimension, $labels) as $key => $label) {
            $result[$key] = ['label' => $label, 'value' => $distinct ? count($totals[$key]) : $totals[$key]];
        }

        return $result;
    }

    /**
     * Tabel silang dua dimensi.
     *
     * @param  iterable<array{facts: EmployeeFacts, row?: array<string, mixed>|null}>  $items
     * @return array{rows: array<string, string>, columns: array<string, string>, values: array<string, array<string, int|float>>}
     */
    public static function cross(iterable $items, string $rowDimension, string $columnDimension, callable $value, bool $distinct = false): array
    {
        $rows = [];
        $columns = [];
        $values = [];
        foreach ($items as $item) {
            $itemValue = $value($item);
            $row = $item['row'] ?? null;
            foreach ($item['facts']->values($rowDimension, $row) as ['key' => $rowKey, 'label' => $rowLabel]) {
                $rows[$rowKey] = $rowLabel;
                foreach ($item['facts']->values($columnDimension, $row) as ['key' => $columnKey, 'label' => $columnLabel]) {
                    $columns[$columnKey] = $columnLabel;
                    if ($distinct) {
                        $values[$rowKey][$columnKey][$itemValue] = true;
                    } else {
                        $values[$rowKey][$columnKey] = ($values[$rowKey][$columnKey] ?? 0) + $itemValue;
                    }
                }
            }
        }

        if ($distinct) {
            $values = array_map(fn (array $cells) => array_map('count', $cells), $values);
        }

        return ['rows' => Dimensions::sort($rowDimension, $rows), 'columns' => Dimensions::sort($columnDimension, $columns), 'values' => $values];
    }

    /**
     * Item karyawan (posisi) dari fakta: [{ facts, row: null }].
     *
     * @param  array<string, EmployeeFacts>  $facts
     * @return list<array{facts: EmployeeFacts, row: null}>
     */
    public static function people(array $facts): array
    {
        return array_values(array_map(fn (EmployeeFacts $item) => ['facts' => $item, 'row' => null], $facts));
    }
}
