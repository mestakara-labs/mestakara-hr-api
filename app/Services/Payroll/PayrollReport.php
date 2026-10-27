<?php

namespace App\Services\Payroll;

use App\Enums\PayrollGroupBy;
use App\Enums\PayrollPeriodStatus;
use App\Models\AttendanceDay;
use App\Models\PayrollPeriod;
use App\Models\PayrollPeriodEvent;
use App\Services\Attendance\AttendancePeriod;
use App\Services\Attendance\DwPay;
use App\Support\DataPeriod;

/**
 * Isi respons GET /payroll: status periode & riwayat kunci, kesiapan data, ringkasan, kelompok, dan baris.
 * Angka & penanda tangan dari PayrollData (hitung sekarang, atau potret kalau periode dikunci).
 */
class PayrollReport
{
    public function build(PayrollData $data, PayrollGroupBy $groupBy): array
    {
        $rows = $data->rows;
        $groups = PayrollGroups::build($rows, $groupBy, $data->signatures);
        $groupKeys = [];
        foreach ($groups as $group) {
            foreach ($group['rows'] as $row) {
                $groupKeys[$row['employee_id']] = $group['key'];
            }
        }
        $totals = PayrollGroups::totals($rows);

        return [
            ...$this->status($data->period),
            'readiness' => $this->readiness($data),
            'summary' => [
                'employees' => count($rows),
                'gross' => $totals['gross'],
                'deductions' => $totals['deductions'],
                'net' => $totals['net'],
            ],
            'groups' => array_map(fn (array $group) => [
                'key' => $group['key'],
                'name' => $group['name'],
                'sub_units' => $group['sub_units'],
                'employee_count' => $group['employee_count'],
                'signature' => $group['signature'],
                'totals' => $group['totals'],
            ], $groups),
            'rows' => array_values(array_map(fn (array $row) => self::rowArray($row, $groupKeys[$row['employee_id']]), $rows)),
        ];
    }

    /**
     * Status periode: { period, status, locked_at, locked_by, can_lock, lock_history }.
     */
    public function status(AttendancePeriod $period): array
    {
        $payrollPeriod = PayrollPeriod::query()->with('locker')->where('period', $period->period)->first();
        $status = $payrollPeriod?->status ?? PayrollPeriodStatus::Draft;
        $isLocked = $status === PayrollPeriodStatus::Locked;

        return [
            'period' => $period->period,
            'status' => $status->value,
            'locked_at' => $isLocked ? $payrollPeriod->locked_at?->toIso8601String() : null,
            'locked_by' => $isLocked ? $payrollPeriod->locker?->name : null,
            /** Boleh dikunci: masih draf & bulannya sudah berakhir. */
            'can_lock' => ! $isLocked && self::hasEnded($period),
            'lock_history' => PayrollPeriodEvent::query()
                ->with('creator')
                ->where('period', $period->period)
                ->orderBy('created_at')
                ->get()
                ->map(fn (PayrollPeriodEvent $event) => [
                    'action' => $event->action->value,
                    'reason' => $event->reason,
                    'by' => $event->creator?->name,
                    'at' => $event->created_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /** Bulan periode sudah lewat (bukan bulan berjalan / bulan depan, waktu WIB)? */
    public static function hasEnded(AttendancePeriod $period): bool
    {
        return $period->period < DataPeriod::today()->format('Y-m');
    }

    /** Satu baris untuk API. */
    public static function rowArray(array $row, string $groupKey): array
    {
        return [
            'employee_id' => $row['employee_id'],
            'registration_number' => $row['registration_number'],
            'name' => $row['name'],
            'employment_status' => $row['employment_status'],
            'position' => $row['position'],
            'sub_unit' => $row['sub_unit_name'] === null ? null : ['name' => $row['sub_unit_name'], 'afdeling_code' => $row['afdeling_code']],
            'payroll_group' => $row['payroll_group_id'] === null ? null : ['id' => $row['payroll_group_id'], 'name' => $row['payroll_group_name']],
            'division' => $row['division_id'] === null ? null : ['id' => $row['division_id'], 'name' => $row['division_name']],
            'group_key' => $groupKey,
            'cost_center' => $row['cost_center_id'] === null ? null : [
                'id' => $row['cost_center_id'], 'code' => $row['cost_center_code'], 'name' => $row['cost_center_name'],
            ],
            'bank_account' => $row['account_number'] === null ? null : [
                'bank_name' => $row['bank_name'], 'account_number' => $row['account_number'], 'account_name' => $row['account_name'],
            ],
            'wage_type' => $row['wage_type'],
            'hke' => $row['hke'],
            'present_days' => $row['present_days'],
            'active_days' => $row['active_days'],
            'calendar_days' => $row['calendar_days'],
            'base_salary' => $row['base_salary'],
            'daily_rate' => $row['daily_rate'],
            'cap_days' => $row['cap_days'],
            'base_pay' => $row['base_pay'],
            'regular_overtime_pay' => $row['regular_overtime_pay'],
            'dw_overtime_pay' => $row['dw_overtime_pay'],
            'overtime_pay' => $row['overtime_pay'],
            'operational_allowance' => $row['operational_allowance'],
            'incentive' => $row['incentive'],
            'meal_allowance' => $row['meal_allowance'],
            'gross' => $row['gross'],
            'deductions' => ['bpjs' => $row['bpjs'], 'pph21' => $row['pph21'], 'unit' => $row['unit']],
            'deductions_total' => $row['deductions_total'],
            'net' => $row['net'],
            'warnings' => $row['warnings'],
        ];
    }

    /** Kesiapan data sebelum dikunci (jumlah karyawan per masalah). */
    private function readiness(PayrollData $data): array
    {
        $rows = collect($data->rows);
        $period = $data->period;

        return [
            'without_scheme' => $rows->whereNull('wage_type')->count(),
            'without_bank_account' => $rows->whereNull('account_number')->count(),
            'without_cost_center' => $rows->whereNull('cost_center_id')->count(),
            'without_payroll_group' => $rows->whereNull('payroll_group_id')->count(),
            'without_division' => $rows->whereNull('division_id')->count(),
            /** Sudah ada data daftar hadir (unggahan / mobile / koreksi) di periode itu? */
            'attendance_uploaded' => AttendanceDay::query()
                ->where('date', '>=', $period->start()->toDateString())
                ->where('date', '<', $period->end()->addDay()->toDateString())
                ->whereIn('employee_id', $rows->keys())
                ->exists(),
            'dw_not_matched' => $rows->filter(fn (array $row) => $row['dw_status'] !== null && $row['dw_status'] !== DwPay::STATUS_MATCH)->count(),
            'k_over_cap' => $rows->filter(fn (array $row) => $row['cap_days'] !== null && $row['present_days'] > $row['cap_days'])->count(),
        ];
    }
}
