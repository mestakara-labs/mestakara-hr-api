<?php

namespace App\Services\Dashboard;

/**
 * DAFTAR WIDGET DASHBOARD (Tahap A): kunci -> judul & pembuatnya. Widget kpi / ringkasan selalu mengabaikan filter.
 *
 *   summary.*    workforce, payroll, attendance, overtime, actions
 *   workforce.*  kpi_total, kpi_gender, kpi_age, kpi_tenure, status_by_group, matrix, demography_*, levels
 *   payroll.*    kpi_net, kpi_average, kpi_overtime_share, kpi_deductions, trend, by_group, matrix, top_cost_center
 *
 * Parameter tambahan group_by: workforce.status_by_group (division|sub_unit|segment) dan
 * payroll.by_group (payroll_group|division|cost_center|segment).
 */
class DashboardWidgets
{
    public const TITLES = [
        'summary.workforce' => 'Karyawan Aktif',
        'summary.payroll' => 'Upah Diterima Casual & DW',
        'summary.attendance' => 'Tingkat Kehadiran',
        'summary.overtime' => 'Jam Lembur',
        'summary.actions' => 'Perlu Tindakan',
        'workforce.kpi_total' => 'Total Karyawan Aktif',
        'workforce.kpi_gender' => 'Komposisi Jenis Kelamin',
        'workforce.kpi_age' => 'Rata-rata Usia',
        'workforce.kpi_tenure' => 'Rata-rata Masa Kerja',
        'workforce.status_by_group' => 'Status Karyawan per Kelompok',
        'workforce.matrix' => 'Bidang × Status Karyawan',
        'workforce.demography_gender' => 'Jenis Kelamin',
        'workforce.demography_age' => 'Kelompok Usia',
        'workforce.demography_education' => 'Pendidikan Terakhir',
        'workforce.levels' => 'Level BRM & Job Grade',
        'payroll.kpi_net' => 'Total Upah Diterima',
        'payroll.kpi_average' => 'Rata-rata Upah per Karyawan',
        'payroll.kpi_overtime_share' => 'Porsi Lembur',
        'payroll.kpi_deductions' => 'Total Potongan',
        'payroll.trend' => 'Tren Upah per Bulan',
        'payroll.by_group' => 'Upah Diterima per Kelompok',
        'payroll.matrix' => 'Kelompok Daftar Upah × Komponen',
        'payroll.top_cost_center' => '10 Cost Center Teratas',
    ];

    /** Pilihan group_by per widget; pilihan pertama = bawaan. */
    public const GROUP_BY = [
        'workforce.status_by_group' => ['division', 'sub_unit', 'segment'],
        'payroll.by_group' => ['payroll_group', 'division', 'cost_center', 'segment'],
    ];

    public function __construct(
        private SummaryWidgets $summary,
        private WorkforceWidgets $workforce,
        private PayrollWidgets $payroll,
    ) {}

    public static function exists(string $key): bool
    {
        return isset(self::TITLES[$key]);
    }

    /** @return array<string, array<string, mixed>> semua widget, periode sama & tanpa filter */
    public function all(DashboardPeriod $period): array
    {
        $widgets = [];
        foreach (array_keys(self::TITLES) as $key) {
            $widgets[$key] = $this->one($key, $period, DashboardFilters::none(), null);
        }

        return $widgets;
    }

    /** @return array<string, mixed> satu widget */
    public function one(string $key, DashboardPeriod $period, DashboardFilters $filters, ?string $groupBy): array
    {
        $title = self::TITLES[$key];

        return match ($key) {
            'summary.workforce' => $this->summary->workforce($key, $title, $period),
            'summary.payroll' => $this->summary->payroll($key, $title, $period),
            'summary.attendance' => $this->summary->attendance($key, $title, $period),
            'summary.overtime' => $this->summary->overtime($key, $title, $period),
            'summary.actions' => $this->summary->actions($key, $title, $period),
            'workforce.kpi_total' => $this->workforce->kpiTotal($key, $title, $period),
            'workforce.kpi_gender' => $this->workforce->kpiGender($key, $title, $period),
            'workforce.kpi_age' => $this->workforce->kpiAge($key, $title, $period),
            'workforce.kpi_tenure' => $this->workforce->kpiTenure($key, $title, $period),
            'workforce.status_by_group' => $this->workforce->statusByGroup($key, $title, $period, $filters, $groupBy),
            'workforce.matrix' => $this->workforce->matrix($key, $title, $period, $filters),
            'workforce.demography_gender' => $this->workforce->demography($key, $title, $period, $filters, 'gender'),
            'workforce.demography_age' => $this->workforce->demography($key, $title, $period, $filters, 'age_group'),
            'workforce.demography_education' => $this->workforce->demography($key, $title, $period, $filters, 'education'),
            'workforce.levels' => $this->workforce->levels($key, $title, $period, $filters),
            'payroll.kpi_net' => $this->payroll->kpiNet($key, $title, $period),
            'payroll.kpi_average' => $this->payroll->kpiAverage($key, $title, $period),
            'payroll.kpi_overtime_share' => $this->payroll->kpiOvertimeShare($key, $title, $period),
            'payroll.kpi_deductions' => $this->payroll->kpiDeductions($key, $title, $period),
            'payroll.trend' => $this->payroll->trend($key, $title, $period, $filters),
            'payroll.by_group' => $this->payroll->byGroup($key, $title, $period, $filters, $groupBy),
            'payroll.matrix' => $this->payroll->matrix($key, $title, $period, $filters),
            'payroll.top_cost_center' => $this->payroll->topCostCenter($key, $title, $period, $filters),
        };
    }
}
