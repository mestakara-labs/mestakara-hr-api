<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Carbon;

/**
 * ANALITIK SDM: satu metrik dikelompokkan per dimensi (dan dipecah per dimensi kedua bila ada split_by).
 *
 *   workforce  headcount = karyawan AKTIF (posisi) per `to`; dengan dimensi bulan = posisi akhir setiap bulan
 *   payroll    angka AKUMULASI baris Daftar Upah Casual & DW dalam rentang (sama dengan Daftar Upah);
 *              headcount = jumlah orang yang dibayar dalam rentang
 *
 * Tanpa split_by:  { summary: { total, average, count, previous }, rows: [{ key, label, value, share }] }
 *   total    = posisi / jumlah seluruh rentang (orang yang sama dihitung sekali)
 *   count    = jumlah kelompok; average = rata-rata nilai per kelompok
 *   previous = total rentang sebelumnya yang sama panjang (filter sama)
 *   share    = value ÷ total × 100. Objek penempatan & kategori objek bisa lebih dari satu per karyawan,
 *              jadi jumlah share dimensi itu bisa lebih dari 100.
 * Dengan split_by: summary yang sama + data bertipe matrix.
 */
class AnalyticsService
{
    public const TOPICS = [
        'workforce' => 'Karyawan',
        'payroll' => 'Upah',
        'attendance' => 'Kehadiran',
        'overtime' => 'Lembur',
        'movement' => 'Pergerakan Karyawan',
    ];

    /** Topik yang sudah tersedia; attendance, overtime, movement menyusul (Tahap B). */
    public const AVAILABLE_TOPICS = ['workforce', 'payroll'];

    /** [topik => [metrik => [label, satuan]]]; metrik pertama = bawaan. */
    public const METRICS = [
        'workforce' => [
            'headcount' => ['Jumlah Karyawan Aktif', 'orang'],
        ],
        'payroll' => [
            'net' => ['Upah Diterima', 'rupiah'],
            'base_pay' => ['Gaji Upah', 'rupiah'],
            'overtime_pay' => ['Lembur', 'rupiah'],
            'allowances' => ['Tunjangan', 'rupiah'],
            'deductions' => ['Potongan', 'rupiah'],
            'headcount' => ['Jumlah Karyawan Dibayar', 'orang'],
        ],
    ];

    /** Dimensi group_by / split_by yang berlaku per topik. */
    public const DIMENSIONS = [
        'workforce' => [
            'sub_unit', 'division', 'segment', 'placement_object', 'cost_center', 'payroll_group',
            'employment_status', 'brm_level', 'gender', 'age_group', 'education', 'month',
        ],
        'payroll' => [
            'sub_unit', 'division', 'segment', 'placement_object', 'cost_center', 'payroll_group',
            'employment_status', 'brm_level', 'gender', 'age_group', 'education', 'month',
        ],
    ];

    public function __construct(
        private WorkforceFacts $workforce,
        private WorkforceWidgets $workforceWidgets,
        private PayrollMonths $payroll,
    ) {}

    /** @return array<string, mixed> */
    public function run(AnalyticsQuery $query): array
    {
        $items = $this->items($query);
        $result = [
            'topic' => $query->topic,
            'metric' => $query->metric,
            'group_by' => $query->groupBy,
            'split_by' => $query->splitBy,
            'unit' => $query->unit(),
            ...$query->period->toArray(),
            'filters' => (object) $query->filters->toArray(),
        ];

        if ($query->splitBy === null) {
            return [...$result, 'type' => 'rows', ...$this->rows($query, $items)];
        }

        return [...$result, 'type' => 'matrix', 'summary' => $this->summary($query, $items), 'data' => $this->matrix($query, $items)];
    }

    /**
     * @param  list<array{facts: EmployeeFacts, row: array<string, mixed>|null}>  $items
     * @return array{summary: array<string, int|float|null>, rows: list<array{key: string, label: string, value: int|float, share: float}>}
     */
    private function rows(AnalyticsQuery $query, array $items): array
    {
        $groups = Aggregate::by($items, $query->groupBy, $this->valueOf($query), $query->countsPeople());
        $total = $this->total($query, $query->period);

        $rows = [];
        foreach ($this->sortAndLimit($query, array_map(fn (array $group) => $group['value'], $groups)) as $key) {
            $rows[] = [
                'key' => (string) $key,
                'label' => $groups[$key]['label'],
                'value' => $groups[$key]['value'],
                'share' => Widget::percent($groups[$key]['value'], $total),
            ];
        }

        return ['summary' => $this->summary($query, $items, $groups), 'rows' => $rows];
    }

    /** @param  list<array{facts: EmployeeFacts, row: array<string, mixed>|null}>  $items */
    private function matrix(AnalyticsQuery $query, array $items): array
    {
        $value = $this->valueOf($query);
        $cross = Aggregate::cross($items, $query->groupBy, $query->splitBy, $value, $query->countsPeople());
        $rowTotals = array_map(fn (array $group) => $group['value'], Aggregate::by($items, $query->groupBy, $value, $query->countsPeople()));
        $columnTotals = array_map(fn (array $group) => $group['value'], Aggregate::by($items, $query->splitBy, $value, $query->countsPeople()));

        $rows = [];
        foreach ($this->sortAndLimit($query, $rowTotals) as $key) {
            $rows[$key] = $cross['rows'][$key];
        }

        return Widget::matrix(
            $rows,
            $cross['columns'],
            $cross['values'],
            $query->unit(),
            array_values(array_map(fn (string|int $key) => $rowTotals[$key], array_keys($rows))),
            $this->total($query, $query->period),
            array_values(array_map(fn (string|int $key) => $columnTotals[$key], array_keys($cross['columns']))),
        );
    }

    /**
     * @param  list<array{facts: EmployeeFacts, row: array<string, mixed>|null}>  $items
     * @param  array<string, array{label: string, value: int|float}>|null  $groups
     * @return array{total: int|float, average: int|float, count: int, previous: int|float}
     */
    private function summary(AnalyticsQuery $query, array $items, ?array $groups = null): array
    {
        $groups ??= Aggregate::by($items, $query->groupBy, $this->valueOf($query), $query->countsPeople());
        $values = array_column($groups, 'value');
        $average = $values === [] ? 0 : array_sum($values) / count($values);

        return [
            'total' => $this->total($query, $query->period),
            'average' => $query->unit() === 'rupiah' ? (int) round($average) : round($average, 1),
            'count' => count($values),
            'previous' => $this->total($query, $query->period->previous()),
        ];
    }

    /**
     * Item yang dikelompokkan: karyawan aktif (workforce) atau baris Daftar Upah per bulan (payroll), sudah disaring.
     *
     * @return list<array{facts: EmployeeFacts, row: array<string, mixed>|null}>
     */
    private function items(AnalyticsQuery $query): array
    {
        if ($query->topic === 'payroll') {
            return $this->payroll->range($query->period, $query->filters);
        }

        if (! $query->usesMonth()) {
            return $this->workforceWidgets->people($query->period, $query->filters);
        }

        // Posisi akhir setiap bulan (bulan berjalan: per `to`).
        $items = [];
        foreach ($query->period->months() as $month) {
            $date = Carbon::parse($month.'-01')->endOfMonth()->startOfDay();
            $date = $date->greaterThan($query->period->to) ? $query->period->to : $date;
            foreach ($this->workforce->activeOn($date) as $facts) {
                if ($query->filters->matches($facts)) {
                    $items[] = ['facts' => $facts, 'row' => null];
                }
            }
        }

        return $items;
    }

    /** Total satu rentang: posisi per `to` (workforce) atau akumulasi rentang (payroll). */
    private function total(AnalyticsQuery $query, DashboardPeriod $period): int|float
    {
        if ($query->topic === 'workforce') {
            return count($this->workforceWidgets->people($period, $query->filters));
        }

        $items = $this->payroll->range($period, $query->filters);
        if ($query->countsPeople()) {
            return count(array_unique(array_map(fn (array $item) => $item['row']['employee_id'], $items)));
        }

        return PayrollMonths::sum($items, $query->metric);
    }

    /** Nilai satu item: id karyawan (hitung orang) atau angka komponen upah. */
    private function valueOf(AnalyticsQuery $query): callable
    {
        if ($query->countsPeople()) {
            return fn (array $item) => $item['row']['employee_id'] ?? $item['facts']->employee->id;
        }

        return fn (array $item) => PayrollMonths::value($item['row'], $query->metric);
    }

    /**
     * Kunci kelompok setelah diurutkan & dibatasi. Masukan sudah dalam urutan baku dimensi (untuk sort=label).
     *
     * @param  array<string, int|float>  $values  [kunci => nilai]
     * @return list<string|int>
     */
    private function sortAndLimit(AnalyticsQuery $query, array $values): array
    {
        if ($query->sort === 'value_desc') {
            arsort($values);
        } elseif ($query->sort === 'value_asc') {
            asort($values);
        }
        $keys = array_keys($values);

        return $query->limit === null ? $keys : array_slice($keys, 0, $query->limit);
    }
}
