<?php

namespace App\Services\Dashboard;

use App\Enums\AttendanceCode;
use App\Enums\PayrollPeriodStatus;
use App\Enums\ProposalStatus;
use App\Enums\TransferStatus;
use App\Models\EmployeeActivation;
use App\Models\EmployeeActivationItem;
use App\Models\EmployeeTermination;
use App\Models\EmployeeTerminationItem;
use App\Models\OvertimeSetting;
use App\Models\PayrollPeriod;
use App\Models\Transfer;
use App\Support\DataPeriod;
use Illuminate\Support\Carbon;

/**
 * WIDGET RINGKASAN (summary.*) — selalu tanpa filter.
 *
 *   workforce   karyawan aktif per `to` (pembanding per `from`); catatan "masuk X · keluar Y" dalam rentang
 *   payroll     total upah diterima Casual & DW (lihat PayrollWidgets::summary)
 *   attendance  K ÷ hari aktif (persen); breakdown hari per kode & hari aktif tanpa kode
 *   overtime    jam lembur reguler + DW; breakdown & jumlah pelanggaran batas jam (Pengaturan Lembur)
 *   actions     daftar hal yang perlu ditindaklanjuti
 */
class SummaryWidgets
{
    /** Batas "pensiun ≤ 6 bulan". */
    private const RETIREMENT_MONTHS = 6;

    public function __construct(
        private WorkforceFacts $workforce,
        private WorkforceWidgets $workforceWidgets,
        private PayrollWidgets $payrollWidgets,
        private AttendanceMonths $attendance,
    ) {}

    public function workforce(string $key, string $title, DashboardPeriod $period): array
    {
        $widget = $this->workforceWidgets->kpiTotal($key, $title, $period);
        $from = $period->from->toDateString();
        $to = $period->to->copy()->addDay()->toDateString();
        $joined = EmployeeActivationItem::query()
            ->whereHas('activation', fn ($query) => $query->where('status', ProposalStatus::Effective))
            ->where('effective_date', '>=', $from)->where('effective_date', '<', $to)->count();
        $left = EmployeeTerminationItem::query()
            ->whereHas('termination', fn ($query) => $query->where('status', ProposalStatus::Effective))
            ->where('effective_date', '>=', $from)->where('effective_date', '<', $to)->count();
        $widget['data']['note'] = "masuk {$joined} · keluar {$left}";

        return $widget;
    }

    public function payroll(string $key, string $title, DashboardPeriod $period): array
    {
        return $this->payrollWidgets->summary($key, $title, $period);
    }

    public function attendance(string $key, string $title, DashboardPeriod $period): array
    {
        $now = $this->attendanceTotals($period);
        $previous = $this->attendanceTotals($period->previous());
        $breakdown = [];
        foreach ([...AttendanceCode::values(), 'kosong'] as $code) {
            $breakdown[] = [
                'key' => $code,
                'label' => $code === 'kosong' ? 'Tanpa kode' : $code.' ('.AttendanceCode::from($code)->label().')',
                'value' => $now['codes'][$code] ?? 0,
            ];
        }

        return Widget::make($key, $title, $period, DashboardFilters::none(), 'kpi', Widget::kpi(
            Widget::percent($now['codes'][AttendanceCode::Work->value], $now['active_days']),
            'persen',
            $previous['active_days'] === 0 ? null : Widget::percent($previous['codes'][AttendanceCode::Work->value], $previous['active_days']),
            $breakdown,
            "Hari K ÷ {$now['active_days']} hari aktif (Casual & Daily Worker)",
        ));
    }

    public function overtime(string $key, string $title, DashboardPeriod $period): array
    {
        $now = $this->overtimeTotals($period);
        $previous = $this->overtimeTotals($period->previous());
        $settings = OvertimeSetting::current();

        return Widget::make($key, $title, $period, DashboardFilters::none(), 'kpi', Widget::kpi(
            $now['regular'] + $now['dw'],
            'jam',
            $previous['regular'] + $previous['dw'],
            [
                ['key' => 'regular', 'label' => 'Lembur Reguler (jam)', 'value' => $now['regular']],
                ['key' => 'dw', 'label' => 'Lembur DW (jam)', 'value' => $now['dw']],
                ['key' => 'over_day', 'label' => "> {$settings->max_hours_per_day} jam/hari (kejadian)", 'value' => $now['over_day']],
                ['key' => 'over_week', 'label' => "> {$settings->max_hours_per_week} jam/minggu (kejadian)", 'value' => $now['over_week']],
            ],
        ));
    }

    public function actions(string $key, string $title, DashboardPeriod $period): array
    {
        $active = $this->workforce->activeOn($period->to);
        $retirementLimit = $period->to->copy()->addMonthsNoOverflow(self::RETIREMENT_MONTHS);
        $dailyWage = array_filter($active, fn (EmployeeFacts $facts) => $facts->hasDailyWage());
        $openMonths = array_filter($period->months(), fn (string $month) => $month < DataPeriod::today()->format('Y-m'));
        $lockedMonths = PayrollPeriod::query()->whereIn('period', $openMonths)->where('status', PayrollPeriodStatus::Locked)->pluck('period')->all();

        $items = [
            ['retirement', 'Pensiun ≤ 6 bulan', count(array_filter($active, fn (EmployeeFacts $facts) => $facts->retirementDate !== null
                && $facts->retirementDate->greaterThanOrEqualTo($period->to) && $facts->retirementDate->lessThanOrEqualTo($retirementLimit))), '/dashboard/karyawan'],
            ['incomplete_data', 'Data karyawan belum lengkap', count(array_filter($active, fn (EmployeeFacts $facts) => $facts->employee->missingFields() !== [])), '/dashboard/karyawan'],
            ['draft_activations', 'Usulan penambahan masih draf', EmployeeActivation::query()->where('status', ProposalStatus::Draft)->count(), '/dashboard/penambahan'],
            ['draft_terminations', 'Usulan penonaktifan masih draf', EmployeeTermination::query()->where('status', ProposalStatus::Draft)->count(), '/dashboard/penonaktifan'],
            ['draft_transfers', 'Usulan mutasi masih draf', Transfer::query()->where('status', TransferStatus::Draft)->count(), '/dashboard/mutasi'],
            ['without_wage_scheme', 'Casual & DW tanpa skema upah', count(array_filter($dailyWage, fn (EmployeeFacts $facts) => ! $facts->hasWageScheme)), '/dashboard/upah/skema'],
            ['without_bank_account', 'Casual & DW tanpa rekening', count(array_filter($dailyWage, fn (EmployeeFacts $facts) => ! $facts->hasBankAccount)), '/dashboard/upah/skema'],
            ['without_cost_center', 'Casual & DW tanpa cost center', count(array_filter($dailyWage, fn (EmployeeFacts $facts) => $facts->costCenter === null)), '/dashboard/upah/skema'],
            ['unlocked_payroll', 'Bulan yang Daftar Upah-nya belum dikunci', count($openMonths) - count($lockedMonths), '/dashboard/upah/daftar-upah'],
        ];

        return Widget::make($key, $title, $period, DashboardFilters::none(), 'list', [
            'items' => array_map(fn (array $item) => ['key' => $item[0], 'label' => $item[1], 'count' => $item[2], 'href' => $item[3]], $items),
        ]);
    }

    /** @return array{codes: array<string, int>, active_days: int} */
    private function attendanceTotals(DashboardPeriod $period): array
    {
        $codes = array_fill_keys([...AttendanceCode::values(), 'kosong'], 0);
        $activeDays = 0;
        foreach ($this->attendance->range($period, DashboardFilters::none()) as $item) {
            $coded = 0;
            foreach ($item['attendance']['codes'] as $code => $count) {
                $codes[$code] += $count;
                $coded += $count;
            }
            $activeDays += $item['row']['active_days'];
            $codes['kosong'] += max($item['row']['active_days'] - $coded, 0);
        }

        return ['codes' => $codes, 'active_days' => $activeDays];
    }

    /** @return array{regular: float, dw: float, over_day: int, over_week: int} jam & jumlah kejadian melewati batas */
    private function overtimeTotals(DashboardPeriod $period): array
    {
        $settings = OvertimeSetting::current();
        $minutes = ['regular' => 0, 'dw' => 0];
        $perDay = [];
        $perWeek = [];
        foreach ($this->attendance->range($period, DashboardFilters::none()) as $item) {
            $employeeId = $item['row']['employee_id'];
            foreach (['regular' => 'regular_overtime', 'dw' => 'dw_overtime'] as $type => $field) {
                foreach ($item['attendance'][$field] as $entry) {
                    $minutes[$type] += $entry['duration_minutes'];
                    $perDay["{$employeeId}|{$entry['date']}"] = ($perDay["{$employeeId}|{$entry['date']}"] ?? 0) + $entry['duration_minutes'];
                    $week = Carbon::parse($entry['date'])->format('o-W');   // minggu Senin–Minggu
                    $perWeek["{$employeeId}|{$week}"] = ($perWeek["{$employeeId}|{$week}"] ?? 0) + $entry['duration_minutes'];
                }
            }
        }

        return [
            'regular' => round($minutes['regular'] / 60, 1),
            'dw' => round($minutes['dw'] / 60, 1),
            'over_day' => count(array_filter($perDay, fn (int $total) => $total > $settings->max_hours_per_day * 60)),
            'over_week' => count(array_filter($perWeek, fn (int $total) => $total > $settings->max_hours_per_week * 60)),
        ];
    }
}
