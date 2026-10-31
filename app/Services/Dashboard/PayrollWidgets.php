<?php

namespace App\Services\Dashboard;

/**
 * WIDGET UPAH (payroll.*): angka AKUMULASI dari baris Daftar Upah setiap bulan dalam rentang (Casual & Daily Worker),
 * jadi SAMA dengan Daftar Upah bulan-bulan itu. Pembanding = rentang sebelumnya yang sama panjang. KPI mengabaikan filter.
 *
 *   Gaji = Gaji Upah · Lembur = reguler + DW · Tunjangan = Ops & Jab + Insentif + Uang Makan · Potongan · Diterima
 */
class PayrollWidgets
{
    /** Kolom matriks Kelompok × komponen. */
    private const MATRIX_COMPONENTS = ['base_pay', 'regular_overtime_pay', 'dw_overtime_pay', 'operational_allowance', 'incentive', 'meal_allowance', 'deductions', 'net'];

    public function __construct(private PayrollMonths $payroll) {}

    /** Total upah diterima. */
    public function kpiNet(string $key, string $title, DashboardPeriod $period): array
    {
        return $this->kpi($key, $title, $period, fn (array $items) => PayrollMonths::sum($items, 'net'), 'rupiah');
    }

    /** Rata-rata upah diterima per karyawan per bulan. */
    public function kpiAverage(string $key, string $title, DashboardPeriod $period): array
    {
        $average = fn (array $items) => $items === [] ? 0 : (int) round(PayrollMonths::sum($items, 'net') / count($items));

        return $this->kpi($key, $title, $period, $average, 'rupiah', null, 'Rata-rata upah diterima per karyawan per bulan');
    }

    /** Porsi lembur terhadap jumlah upah (persen). */
    public function kpiOvertimeShare(string $key, string $title, DashboardPeriod $period): array
    {
        $share = fn (array $items) => Widget::percent(PayrollMonths::sum($items, 'overtime_pay'), PayrollMonths::sum($items, 'gross'));

        return $this->kpi($key, $title, $period, $share, 'persen', null, 'Upah lembur ÷ jumlah upah & tunjangan');
    }

    /** Total potongan; breakdown BPJS / PPh 21 / Kebun-Unit. */
    public function kpiDeductions(string $key, string $title, DashboardPeriod $period): array
    {
        $breakdown = fn (array $items) => [
            ['key' => 'bpjs', 'label' => 'BPJS TK/KES', 'value' => PayrollMonths::sum($items, 'bpjs')],
            ['key' => 'pph21', 'label' => 'PPIP/PPh 21', 'value' => PayrollMonths::sum($items, 'pph21')],
            ['key' => 'unit', 'label' => 'Kebun/Unit', 'value' => PayrollMonths::sum($items, 'unit')],
        ];

        return $this->kpi($key, $title, $period, fn (array $items) => PayrollMonths::sum($items, 'deductions'), 'rupiah', $breakdown);
    }

    /** Ringkasan upah: diterima + breakdown gaji / lembur / tunjangan / potongan. */
    public function summary(string $key, string $title, DashboardPeriod $period): array
    {
        $breakdown = fn (array $items) => array_map(
            fn (string $component) => ['key' => $component, 'label' => PayrollMonths::COMPONENTS[$component], 'value' => PayrollMonths::sum($items, $component)],
            ['base_pay', 'overtime_pay', 'allowances', 'deductions'],
        );

        return $this->kpi($key, $title, $period, fn (array $items) => PayrollMonths::sum($items, 'net'), 'rupiah', $breakdown);
    }

    /** Per bulan: batang bertumpuk gaji, lembur, tunjangan; garis diterima. */
    public function trend(string $key, string $title, DashboardPeriod $period, DashboardFilters $filters): array
    {
        $months = [];
        $byMonth = [];
        foreach ($period->months() as $month) {
            $months[$month] = DashboardPeriod::monthLabel($month);
            $byMonth[$month] = $this->payroll->month($month, $filters);
        }

        $series = [];
        foreach (['base_pay', 'overtime_pay', 'allowances'] as $component) {
            $series[] = [
                'key' => $component,
                'label' => PayrollMonths::COMPONENTS[$component],
                'kind' => 'bar',
                'stack' => 'komponen',
                'values' => array_values(array_map(fn (array $items) => PayrollMonths::sum($items, $component), $byMonth)),
            ];
        }
        $series[] = ['key' => 'net', 'label' => 'Diterima', 'kind' => 'line', 'values' => array_values(array_map(fn (array $items) => PayrollMonths::sum($items, 'net'), $byMonth))];

        return Widget::make($key, $title, $period, $filters, 'series', Widget::series($months, $series, 'rupiah'));
    }

    /** Diterima per kelompok daftar upah / bidang / cost center / kategori objek, urut terbesar. */
    public function byGroup(string $key, string $title, DashboardPeriod $period, DashboardFilters $filters, ?string $groupBy): array
    {
        $groups = Aggregate::by($this->payroll->range($period, $filters), $groupBy ?? 'payroll_group', fn (array $item) => PayrollMonths::value($item['row'], 'net'));
        uasort($groups, fn (array $a, array $b) => $b['value'] <=> $a['value']);

        return Widget::make($key, $title, $period, $filters, 'series', Widget::series(
            array_map(fn (array $group) => $group['label'], $groups),
            [['key' => 'net', 'label' => 'Diterima', 'kind' => 'bar', 'values' => array_values(array_column($groups, 'value'))]],
            'rupiah',
        ));
    }

    /**
     * Kelompok Daftar Upah × komponen. Karena kolomnya komponen berbeda (termasuk potongan), total baris & total
     * keseluruhan = DITERIMA, bukan penjumlahan semua kolom.
     */
    public function matrix(string $key, string $title, DashboardPeriod $period, DashboardFilters $filters): array
    {
        $items = $this->payroll->range($period, $filters);
        $groups = Aggregate::by($items, 'payroll_group', fn () => 0);
        $values = [];
        foreach ($items as $item) {
            $group = $item['facts']->keys('payroll_group', $item['row'])[0];
            foreach (self::MATRIX_COMPONENTS as $component) {
                $values[$group][$component] = ($values[$group][$component] ?? 0) + PayrollMonths::value($item['row'], $component);
            }
        }
        $columns = [];
        foreach (self::MATRIX_COMPONENTS as $component) {
            $columns[$component] = PayrollMonths::COMPONENTS[$component];
        }
        $rowTotals = array_values(array_map(fn (string $group) => $values[$group]['net'] ?? 0, array_keys($groups)));

        return Widget::make($key, $title, $period, $filters, 'matrix', Widget::matrix(
            array_map(fn (array $group) => $group['label'], $groups),
            $columns,
            $values,
            'rupiah',
            $rowTotals,
            array_sum($rowTotals),
        ));
    }

    /** 10 cost center dengan upah diterima terbesar. */
    public function topCostCenter(string $key, string $title, DashboardPeriod $period, DashboardFilters $filters): array
    {
        $items = $this->payroll->range($period, $filters);
        $totalNet = PayrollMonths::sum($items, 'net');
        $rows = [];
        foreach ($items as ['row' => $row]) {
            $id = $row['cost_center_id'] ?? EmployeeFacts::NONE_KEY;
            $rows[$id] ??= [
                'key' => $id,
                'code' => $row['cost_center_code'] ?? '-',
                'name' => $row['cost_center_name'] ?? Dimensions::noneLabel('cost_center'),
                'employees' => [],
                'net' => 0,
            ];
            $rows[$id]['employees'][$row['employee_id']] = true;
            $rows[$id]['net'] += $row['net'];
        }
        usort($rows, fn (array $a, array $b) => $b['net'] <=> $a['net']);
        $rows = array_map(fn (array $row) => [
            ...$row,
            'employees' => count($row['employees']),
            'share' => Widget::percent($row['net'], $totalNet),
        ], array_slice($rows, 0, 10));

        return Widget::make($key, $title, $period, $filters, 'table', [
            'columns' => [
                ['key' => 'code', 'label' => 'Kode'],
                ['key' => 'name', 'label' => 'Uraian'],
                ['key' => 'employees', 'label' => 'Karyawan', 'unit' => 'orang'],
                ['key' => 'net', 'label' => 'Diterima', 'unit' => 'rupiah'],
                ['key' => 'share', 'label' => 'Porsi', 'unit' => 'persen'],
            ],
            'rows' => $rows,
        ]);
    }

    /** KPI dengan pembanding rentang sebelumnya; tanpa filter. */
    private function kpi(string $key, string $title, DashboardPeriod $period, callable $value, string $unit, ?callable $breakdown = null, ?string $note = null): array
    {
        $now = $this->payroll->range($period, DashboardFilters::none());
        $previous = $this->payroll->range($period->previous(), DashboardFilters::none());

        return Widget::make($key, $title, $period, DashboardFilters::none(), 'kpi', Widget::kpi(
            $value($now),
            $unit,
            $value($previous),
            $breakdown === null ? null : $breakdown($now),
            $note,
        ));
    }
}
