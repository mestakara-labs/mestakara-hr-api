<?php

namespace App\Services\Payroll;

use App\Enums\WageType;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\PayrollGroup;
use App\Support\DataPeriod;
use Illuminate\Support\Carbon;

/**
 * Menyusun ISI template impor Skema Upah: SEMUA karyawan aktif (Tetap, PKWT, Casual, DW) beserta nilai
 * yang berlaku pada tanggal acuan periode. File Excel-nya ditulis oleh WageSchemeTemplateWorkbook.
 *
 *   skema upah   hanya diisi untuk Casual & Daily Worker
 *   Berlaku Mulai diisi tanggal 1 bulan periode (wajib hanya untuk baris yang diubah)
 */
class WageSchemeTemplateService
{
    public function __construct(private ActivePayrollEmployees $activeEmployees) {}

    /**
     * Baris template: [kunci kolom (WageSchemeTemplateColumns) => nilai] per karyawan.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(?string $period): array
    {
        $asOf = DataPeriod::asOf($period);
        $date = $asOf ?? DataPeriod::asOfOrToday(null);
        $effectiveDate = Carbon::parse($date->format('Y-m').'-01');

        return $this->activeEmployees->get($asOf, dailyWageOnly: false)
            ->map(fn (Employee $employee) => $this->row($employee, $date, $effectiveDate))
            ->all();
    }

    /**
     * Daftar pilihan dropdown di sheet "Daftar": [nama daftar => [judul, nilai...]].
     *
     * @return array<string, array{title: string, values: list<string>}>
     */
    public function lists(): array
    {
        return [
            'wage_types' => ['title' => 'Jenis Upah', 'values' => WageType::values()],
            'yes_no' => ['title' => 'Ya/Tidak', 'values' => [WageSchemeTemplateColumns::YES, WageSchemeTemplateColumns::NO]],
            'payroll_groups' => ['title' => 'Kelompok Daftar Upah', 'values' => PayrollGroup::query()->orderBy('sort_order')->orderBy('name')->pluck('name')->all()],
            'cost_centers' => ['title' => 'Kode Cost Center', 'values' => CostCenter::query()->orderBy('code')->pluck('code')->all()],
        ];
    }

    /** Nama file, mis. "Template Skema Upah - Oktober 2026.xlsx". */
    public function fileName(?string $period): string
    {
        return 'Template Skema Upah - '.DataPeriod::label($period).'.xlsx';
    }

    /** @return array<string, mixed> */
    private function row(Employee $employee, Carbon $date, Carbon $effectiveDate): array
    {
        $scheme = $employee->hasDailyWage() ? $employee->wageSchemeOn($date) : null;
        $bankAccount = $employee->bankAccountOn($date);

        return [
            'employee_id' => $employee->id,
            'registration_number' => $employee->registration_number,
            'name' => $employee->name,
            'employment_status' => $employee->employment_status,
            'sub_unit' => $employee->mainAssignment($date)?->subUnit?->name,

            'wage_type' => $scheme?->wage_type->value,
            'base_salary' => $scheme?->base_salary,
            'working_days' => $scheme?->working_days,
            'is_capped' => $scheme === null ? null : $this->yesNo($scheme->is_capped),
            'operational_position_allowance' => $scheme?->operational_position_allowance,
            'incentive' => $scheme?->incentive,
            'has_meal_allowance' => $scheme === null ? null : $this->yesNo($scheme->has_meal_allowance),
            'meal_allowance_per_day' => $scheme?->has_meal_allowance ? $scheme->meal_allowance_per_day : null,
            'payroll_group' => $scheme?->payrollGroup?->name,

            'bank_name' => $bankAccount?->bank_name,
            'account_number' => $bankAccount?->account_number,
            'account_holder' => $bankAccount?->account_holder,
            'cost_center_code' => $employee->costCenterOn($date)?->costCenter->code,
            'effective_date' => $effectiveDate,
            'notes' => null,
        ];
    }

    private function yesNo(bool $value): string
    {
        return $value ? WageSchemeTemplateColumns::YES : WageSchemeTemplateColumns::NO;
    }
}
