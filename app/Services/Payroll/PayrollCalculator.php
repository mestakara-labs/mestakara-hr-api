<?php

namespace App\Services\Payroll;

use App\Enums\AttendanceCode;
use App\Enums\WageType;
use App\Models\PayrollDeduction;
use App\Services\Attendance\AttendanceEmployees;
use App\Services\Attendance\AttendancePeriod;
use App\Services\Attendance\AttendanceSummaryService;
use App\Services\Attendance\DwPay;
use App\Services\Attendance\PeriodEmployee;
use Illuminate\Database\Eloquent\Collection;

/**
 * RUMUS DAFTAR UPAH per karyawan Casual & Daily Worker yang aktif minimal satu hari dalam periode.
 * Skema, rekening, cost center, & jabatan: yang berlaku pada tanggal acuan periode.
 *
 *   Absen (present_days)  = jumlah kode K
 *   Gaji Upah (base_pay)
 *     Harian        = min(K, batas hari kalau dibatasi) × Rp/hari          (K > batas = peringatan)
 *     Flat Bulanan  = Gaji Pokok; kalau hari aktif < hari kalender: round(Gaji Pokok × hari aktif ÷ hari kalender)
 *   Lembur          = lembur reguler + lembur DW (per tanggal, aturan Pengaturan Lembur, lihat DwPay)
 *   Uang Makan      = K × uang makan/hari (kalau skema memakai uang makan)
 *   Ops & Jab, Insentif = dari skema
 *   Jumlah (gross)  = Gaji Upah + Lembur + Ops & Jab + Insentif + Uang Makan
 *   Potongan        = BPJS + PPh 21 + Kebun/Unit (diisi manual, bawaan 0)
 *   Diterima (net)  = Jumlah − Potongan
 *   Tanpa skema     = semua angka 0 + peringatan "Skema upah belum diatur"
 *
 * Hasilnya baris DATAR (kolomnya sama dengan tabel payroll_snapshot_rows), urut nama, dikunci id karyawan.
 */
class PayrollCalculator
{
    public function __construct(
        private AttendanceEmployees $attendanceEmployees,
        private AttendanceSummaryService $summaryService,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function rows(AttendancePeriod $period): array
    {
        $employees = $this->attendanceEmployees->forPeriod($period);
        (new Collection($employees->map(fn (PeriodEmployee $item) => $item->employee)->values()->all()))
            ->loadMissing(['bankAccounts', 'assignments.position.division']);

        $summary = $this->summaryService->forPeriod($period->period, $employees->keys());
        $deductions = PayrollDeduction::query()
            ->where('period', $period->period)
            ->whereIn('employee_id', $employees->keys())
            ->get()
            ->keyBy('employee_id');
        $pay = DwPay::make();
        $calendarDays = count($period->dates());

        $rows = [];
        foreach ($employees as $id => $item) {
            $rows[$id] = $this->row($item, $summary[$id], $deductions->get($id), $pay, $calendarDays);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function row(PeriodEmployee $item, array $summary, ?PayrollDeduction $deduction, DwPay $pay, int $calendarDays): array
    {
        $scheme = $item->scheme;
        $presentDays = $summary['codes'][AttendanceCode::Work->value];
        $activeDays = count($item->activeDates());
        $warnings = [];
        $amounts = [
            'base_pay' => 0, 'regular_overtime_pay' => 0, 'dw_overtime_pay' => 0,
            'operational_allowance' => 0, 'incentive' => 0, 'meal_allowance' => 0,
        ];

        if ($scheme === null) {
            $warnings[] = 'Skema upah belum diatur';
        } else {
            $capDays = $item->capDays();

            if ($scheme->wage_type === WageType::Daily) {
                $paidDays = $capDays === null ? $presentDays : min($presentDays, $capDays);
                $amounts['base_pay'] = $paidDays * $scheme->dailyRate();
            } else {
                $amounts['base_pay'] = $activeDays < $calendarDays
                    ? (int) round($scheme->base_salary * $activeDays / $calendarDays)
                    : $scheme->base_salary;
            }

            if ($capDays !== null && $presentDays > $capDays) {
                $warnings[] = "K {$presentDays} melebihi batas {$capDays} hari; yang dibayar {$capDays}";
            }

            $amounts['regular_overtime_pay'] = $pay->payForEntries($scheme, $summary['regular_overtime']);
            $amounts['dw_overtime_pay'] = $pay->payForEntries($scheme, $summary['dw_overtime']);
            $amounts['operational_allowance'] = $scheme->operational_position_allowance;
            $amounts['incentive'] = $scheme->incentive;
            $amounts['meal_allowance'] = $scheme->has_meal_allowance ? $presentDays * $scheme->meal_allowance_per_day : 0;
        }

        $gross = array_sum($amounts);
        $bpjs = $deduction?->bpjs ?? 0;
        $pph21 = $deduction?->pph21 ?? 0;
        $unit = $deduction?->unit ?? 0;
        $deductionsTotal = $bpjs + $pph21 + $unit;

        if ($deductionsTotal > $gross) {
            $warnings[] = 'Potongan melebihi jumlah upah';
        }

        return [
            ...$this->identity($item),
            'wage_type' => $scheme?->wage_type->value,
            'hke' => $scheme?->working_days ?? 0,
            'present_days' => $presentDays,
            'active_days' => $activeDays,
            'calendar_days' => $calendarDays,
            'base_salary' => $scheme?->base_salary ?? 0,
            'daily_rate' => $scheme?->dailyRate() ?? 0,
            'cap_days' => $item->capDays(),
            'base_pay' => $amounts['base_pay'],
            'regular_overtime_pay' => $amounts['regular_overtime_pay'],
            'dw_overtime_pay' => $amounts['dw_overtime_pay'],
            'overtime_pay' => $amounts['regular_overtime_pay'] + $amounts['dw_overtime_pay'],
            'operational_allowance' => $amounts['operational_allowance'],
            'incentive' => $amounts['incentive'],
            'meal_allowance' => $amounts['meal_allowance'],
            'gross' => $gross,
            'bpjs' => $bpjs,
            'pph21' => $pph21,
            'unit' => $unit,
            'deductions_total' => $deductionsTotal,
            'net' => $gross - $deductionsTotal,
            'warnings' => $warnings,
            'dw_status' => $this->dwStatus($item, $summary, $pay),
        ];
    }

    /** Identitas & data dasar upah pada tanggal acuan. */
    private function identity(PeriodEmployee $item): array
    {
        $employee = $item->employee;
        $group = $item->scheme?->payrollGroup;
        $division = $item->assignment?->position->division;
        $costCenter = $item->costCenter?->costCenter;
        $bank = $item->referenceDate === null ? null : $employee->bankAccountOn($item->referenceDate);

        return [
            'employee_id' => $employee->id,
            'registration_number' => $employee->registration_number,
            'name' => $employee->name,
            'employment_status' => $employee->employment_status,
            'position' => $item->positionTitle(),
            'sub_unit_name' => $item->subUnit()['name'] ?? null,
            'afdeling_code' => $item->subUnit()['afdeling_code'] ?? null,
            'payroll_group_id' => $group?->id,
            'payroll_group_name' => $group?->name,
            'division_id' => $division?->id,
            'division_name' => $division?->name,
            'cost_center_id' => $costCenter?->id,
            'cost_center_code' => $costCenter?->code,
            'cost_center_name' => $costCenter?->name,
            'bank_name' => $bank?->bank_name,
            'account_number' => $bank?->account_number,
            'account_name' => $bank?->account_holder,
        ];
    }

    /** Status lembur DW (aturan DwPay), null kalau karyawan tidak punya hari DW maupun lembur DW. */
    private function dwStatus(PeriodEmployee $item, array $summary, DwPay $pay): ?string
    {
        $dwDays = $summary['codes'][AttendanceCode::DailyWorker->value];
        $entries = $summary['dw_overtime'];

        if ($dwDays === 0 && $entries === []) {
            return null;
        }

        $scheme = $item->scheme;
        $dwPay = $scheme === null ? 0 : $pay->payForEntries($scheme, $entries);
        $target = $scheme === null ? 0 : $pay->target($scheme, $dwDays);

        return $pay->status($scheme, $dwDays, count($entries), $dwPay, $target);
    }
}
