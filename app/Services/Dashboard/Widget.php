<?php

namespace App\Services\Dashboard;

/**
 * BENTUK DATA WIDGET (sama untuk semua kartu): { key, title, from, to, filters, type, data }.
 *
 *   kpi           { value, unit, previous, change_percent, breakdown?, note? }
 *   series        { categories: [{ key, label }], series: [{ key, label, kind, stack?, values }], unit }
 *   distribution  { items: [{ key, label, value }], total, unit }
 *   matrix        { rows, columns, values, row_totals, column_totals, total, unit }
 *   table         { columns: [{ key, label, unit? }], rows: [{ key, ...nilai, href? }] }
 *   list          { items: [{ key, label, count, href }] }
 *
 * Satuan: orang · rupiah · persen · jam · hari · tahun.
 */
class Widget
{
    /** @return array<string, mixed> */
    public static function make(string $key, string $title, DashboardPeriod $period, DashboardFilters $filters, string $type, array $data): array
    {
        return [
            'key' => $key,
            'title' => $title,
            ...$period->toArray(),
            'filters' => (object) $filters->toArray(),
            'type' => $type,
            'data' => $data,
        ];
    }

    /**
     * Data kpi. change_percent = (nilai − sebelumnya) ÷ sebelumnya × 100, null bila sebelumnya kosong / 0.
     *
     * @param  list<array{key: string, label: string, value: int|float}>|null  $breakdown
     */
    public static function kpi(int|float $value, string $unit, int|float|null $previous, ?array $breakdown = null, ?string $note = null): array
    {
        $data = [
            'value' => $value,
            'unit' => $unit,
            'previous' => $previous,
            'change_percent' => $previous === null || (float) $previous === 0.0 ? null : round(($value - $previous) / abs($previous) * 100, 1),
        ];
        if ($breakdown !== null) {
            $data['breakdown'] = $breakdown;
        }
        if ($note !== null) {
            $data['note'] = $note;
        }

        return $data;
    }

    /**
     * @param  array<string, string>  $categories  [key => label], urut tampil
     * @param  list<array{key: string, label: string, kind: string, stack?: string, values: list<int|float>}>  $series
     */
    public static function series(array $categories, array $series, string $unit): array
    {
        return ['categories' => self::keyLabels($categories), 'series' => $series, 'unit' => $unit];
    }

    /** @param  array<string, array{label: string, value: int|float}>  $items  urut tampil */
    public static function distribution(array $items, string $unit, int|float|null $total = null): array
    {
        $list = array_map(fn (string $key, array $item) => ['key' => $key, 'label' => $item['label'], 'value' => $item['value']], array_keys($items), $items);

        return ['items' => $list, 'total' => $total ?? array_sum(array_column($list, 'value')), 'unit' => $unit];
    }

    /**
     * @param  array<string, string>  $rows  [key => label]
     * @param  array<string, string>  $columns  [key => label]
     * @param  array<string, array<string, int|float>>  $values  [baris][kolom] => nilai (yang tidak ada = 0)
     * @param  list<int|float>|null  $rowTotals  null = jumlah per baris
     * @param  list<int|float>|null  $columnTotals  null = jumlah per kolom
     */
    public static function matrix(array $rows, array $columns, array $values, string $unit, ?array $rowTotals = null, int|float|null $total = null, ?array $columnTotals = null): array
    {
        $grid = [];
        foreach (array_keys($rows) as $row) {
            $grid[] = array_map(fn (string $column) => $values[$row][$column] ?? 0, array_keys($columns));
        }
        $columnTotals ??= array_map(fn (int $index) => array_sum(array_column($grid, $index)), array_keys(array_keys($columns)));
        $rowTotals ??= array_map('array_sum', $grid);

        return [
            'rows' => self::keyLabels($rows),
            'columns' => self::keyLabels($columns),
            'values' => $grid,
            'row_totals' => $rowTotals,
            'column_totals' => $columnTotals,
            'total' => $total ?? array_sum($rowTotals),
            'unit' => $unit,
        ];
    }

    /** Persentase dibulatkan 1 angka di belakang koma; 0 bila pembagi 0. */
    public static function percent(int|float $part, int|float $whole): float
    {
        return (float) $whole === 0.0 ? 0.0 : round($part / $whole * 100, 1);
    }

    /** @return list<array{key: string, label: string}> */
    private static function keyLabels(array $items): array
    {
        return array_map(fn (string|int $key, string $label) => ['key' => (string) $key, 'label' => $label], array_keys($items), $items);
    }
}
