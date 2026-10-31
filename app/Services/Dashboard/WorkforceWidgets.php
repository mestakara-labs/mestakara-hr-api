<?php

namespace App\Services\Dashboard;

use App\Enums\EducationLevel;
use App\Enums\EmploymentStatus;
use App\Enums\Gender;

/**
 * WIDGET KOMPOSISI KARYAWAN (workforce.*): angka POSISI = karyawan AKTIF pada tanggal `to` (semua status kepegawaian).
 * Pembanding (previous) KPI = posisi pada tanggal `from`. KPI mengabaikan filter.
 */
class WorkforceWidgets
{
    /** Kelompok masa kerja: [key => [batas atas tahun (eksklusif), label]]. */
    private const TENURE_GROUPS = [
        'lt1' => [1, '< 1 tahun'],
        '1_5' => [5, '1–5 tahun'],
        '5_10' => [10, '5–10 tahun'],
        '10_20' => [20, '10–20 tahun'],
        'gte20' => [PHP_INT_MAX, '≥ 20 tahun'],
    ];

    public function __construct(private WorkforceFacts $workforce) {}

    /** Karyawan aktif per `to`; breakdown per status. */
    public function kpiTotal(string $key, string $title, DashboardPeriod $period): array
    {
        $now = $this->workforce->activeOn($period->to);

        return Widget::make($key, $title, $period, DashboardFilters::none(), 'kpi', Widget::kpi(
            count($now),
            'orang',
            count($this->workforce->activeOn($period->from)),
            $this->statusBreakdown($now),
        ));
    }

    /** Porsi perempuan (persen); breakdown jumlah per jenis kelamin. */
    public function kpiGender(string $key, string $title, DashboardPeriod $period): array
    {
        $share = fn (array $facts) => Widget::percent(count(array_filter($facts, fn (EmployeeFacts $item) => $item->employee->gender === Gender::Female->value)), count($facts));
        $now = $this->workforce->activeOn($period->to);
        $breakdown = $this->breakdown(Aggregate::by(Aggregate::people($now), 'gender', fn () => 1));

        return Widget::make($key, $title, $period, DashboardFilters::none(), 'kpi', Widget::kpi(
            $share($now),
            'persen',
            $share($this->workforce->activeOn($period->from)),
            $breakdown,
            'Porsi karyawan perempuan',
        ));
    }

    /** Rata-rata usia (tahun, yang tanggal lahirnya terisi); breakdown per kelompok usia. */
    public function kpiAge(string $key, string $title, DashboardPeriod $period): array
    {
        $now = $this->workforce->activeOn($period->to);

        return Widget::make($key, $title, $period, DashboardFilters::none(), 'kpi', Widget::kpi(
            $this->averageAge($now),
            'tahun',
            $this->averageAge($this->workforce->activeOn($period->from)),
            $this->breakdown(Aggregate::by(Aggregate::people($now), 'age_group', fn () => 1)),
            'Rata-rata usia karyawan aktif',
        ));
    }

    /** Rata-rata masa kerja (tahun, tanpa masa nonaktif); breakdown per kelompok masa kerja. */
    public function kpiTenure(string $key, string $title, DashboardPeriod $period): array
    {
        $now = $this->workforce->activeOn($period->to);
        $groups = array_map(fn (array $group) => ['key' => '', 'label' => $group[1], 'value' => 0], self::TENURE_GROUPS);
        foreach ($now as $facts) {
            foreach (self::TENURE_GROUPS as $groupKey => [$maxYears]) {
                if ($facts->serviceMonths() < $maxYears * 12) {
                    $groups[$groupKey]['value']++;
                    break;
                }
            }
        }

        return Widget::make($key, $title, $period, DashboardFilters::none(), 'kpi', Widget::kpi(
            $this->averageTenure($now),
            'tahun',
            $this->averageTenure($this->workforce->activeOn($period->from)),
            array_map(fn (string $groupKey, array $group) => [...$group, 'key' => $groupKey], array_keys($groups), $groups),
            'Rata-rata masa kerja (tanpa masa nonaktif)',
        ));
    }

    /** Batang bertumpuk per status untuk setiap bidang / sub unit / kategori objek. */
    public function statusByGroup(string $key, string $title, DashboardPeriod $period, DashboardFilters $filters, ?string $groupBy): array
    {
        $groupBy ??= 'division';
        $cross = Aggregate::cross($this->people($period, $filters), $groupBy, 'employment_status', fn () => 1);
        $series = [];
        foreach ($this->statuses() as $status => $label) {
            $series[] = [
                'key' => $status,
                'label' => $label,
                'kind' => 'bar',
                'stack' => 'status',
                'values' => array_map(fn (string $group) => $cross['values'][$group][$status] ?? 0, array_keys($cross['rows'])),
            ];
        }

        return Widget::make($key, $title, $period, $filters, 'series', Widget::series($cross['rows'], $series, 'orang'));
    }

    /** Bidang × Status. */
    public function matrix(string $key, string $title, DashboardPeriod $period, DashboardFilters $filters): array
    {
        $cross = Aggregate::cross($this->people($period, $filters), 'division', 'employment_status', fn () => 1);

        return Widget::make($key, $title, $period, $filters, 'matrix', Widget::matrix($cross['rows'], $this->statuses(), $cross['values'], 'orang'));
    }

    /** Distribusi satu dimensi demografi (gender / age_group / education), termasuk kategori yang 0. */
    public function demography(string $key, string $title, DashboardPeriod $period, DashboardFilters $filters, string $dimension): array
    {
        $items = Aggregate::by($this->people($period, $filters), $dimension, fn () => 1);
        $baseline = match ($dimension) {
            'gender' => array_combine(Gender::values(), Gender::values()),
            'age_group' => array_map(fn (array $group) => $group[1], Dimensions::AGE_GROUPS),
            'education' => array_combine(EducationLevel::values(), EducationLevel::values()),
        };
        $all = [];
        foreach (Dimensions::sort($dimension, $baseline + array_map(fn (array $item) => $item['label'], $items)) as $itemKey => $label) {
            $all[$itemKey] = ['label' => $label, 'value' => $items[$itemKey]['value'] ?? 0];
        }

        return Widget::make($key, $title, $period, $filters, 'distribution', Widget::distribution($all, 'orang'));
    }

    /** Jumlah per level BRM, bertumpuk per job grade. */
    public function levels(string $key, string $title, DashboardPeriod $period, DashboardFilters $filters): array
    {
        $levels = [];
        foreach (range(1, 6) as $level) {
            $levels[(string) $level] = "BRM-{$level}";
        }
        $levels[EmployeeFacts::NONE_KEY] = Dimensions::noneLabel('brm_level');

        $counts = [];
        $grades = [];
        foreach ($this->people($period, $filters) as ['facts' => $facts]) {
            $level = $facts->brmLevel === null ? EmployeeFacts::NONE_KEY : (string) $facts->brmLevel;
            $grade = $facts->jobGrade === null ? EmployeeFacts::NONE_KEY : (string) $facts->jobGrade;
            $grades[$grade] = $facts->jobGrade === null ? 'Tanpa Job Grade' : "Job Grade {$facts->jobGrade}";
            $counts[$grade][$level] = ($counts[$grade][$level] ?? 0) + 1;
        }
        uksort($grades, fn (string $a, string $b) => ($a === EmployeeFacts::NONE_KEY) <=> ($b === EmployeeFacts::NONE_KEY) ?: (int) $a <=> (int) $b);

        $series = [];
        foreach ($grades as $grade => $label) {
            $series[] = [
                'key' => $grade,
                'label' => $label,
                'kind' => 'bar',
                'stack' => 'job_grade',
                'values' => array_map(fn (string $level) => $counts[$grade][$level] ?? 0, array_keys($levels)),
            ];
        }

        return Widget::make($key, $title, $period, $filters, 'series', Widget::series($levels, $series, 'orang'));
    }

    /** @return list<array{facts: EmployeeFacts, row: null}> karyawan aktif per `to` yang lolos filter */
    public function people(DashboardPeriod $period, DashboardFilters $filters): array
    {
        return array_values(array_filter(
            Aggregate::people($this->workforce->activeOn($period->to)),
            fn (array $item) => $filters->matches($item['facts']),
        ));
    }

    /** @param  array<string, EmployeeFacts>  $facts */
    public function statusBreakdown(array $facts): array
    {
        $counts = array_count_values(array_map(fn (EmployeeFacts $item) => $item->employee->employment_status, $facts));

        return array_map(fn (string $status, string $label) => ['key' => $status, 'label' => $label, 'value' => $counts[$status] ?? 0], array_keys($this->statuses()), $this->statuses());
    }

    /** @return array<string, string> [status => label ringkas] */
    private function statuses(): array
    {
        $statuses = [];
        foreach (EmploymentStatus::values() as $status) {
            $statuses[$status] = Dimensions::statusLabel($status);
        }

        return $statuses;
    }

    /** @param  array<string, array{label: string, value: int|float}>  $items */
    private function breakdown(array $items): array
    {
        return array_map(fn (string $key, array $item) => ['key' => $key, ...$item], array_keys($items), $items);
    }

    /** @param  array<string, EmployeeFacts>  $facts */
    private function averageAge(array $facts): float
    {
        $ages = array_filter(array_map(fn (EmployeeFacts $item) => $item->age, $facts), fn (?int $age) => $age !== null);

        return $ages === [] ? 0.0 : round(array_sum($ages) / count($ages), 1);
    }

    /** @param  array<string, EmployeeFacts>  $facts */
    private function averageTenure(array $facts): float
    {
        return $facts === [] ? 0.0 : round(array_sum(array_map(fn (EmployeeFacts $item) => $item->serviceMonths(), $facts)) / count($facts) / 12, 1);
    }
}
