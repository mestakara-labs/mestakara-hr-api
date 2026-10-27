<?php

namespace App\Services\Payroll;

use App\Enums\EmployeeActiveStatus;
use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Services\EmployeeStatusService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * KARYAWAN AKTIF untuk halaman skema upah & template impor, lengkap dengan data dasar upahnya.
 *
 * Aturan "aktif" SAMA dengan GET /employees?period=:
 *   tanpa periode  -> kolom status karyawan saat ini (Aktif)
 *   dengan periode -> status dihitung pada tanggal acuan periode (App\Support\DataPeriod)
 */
class ActivePayrollEmployees
{
    /** Relasi yang dibutuhkan WageSchemeEmployeeResource & template impor. */
    public const RELATIONS = [
        'activationItems.activation', 'terminationItems.termination',
        'assignments.position', 'assignments.subUnit', 'assignments.placementObjects',
        'wageSchemes.payrollGroup', 'wageSchemes.creator', 'bankAccounts', 'costCenterHistories.costCenter',
    ];

    public function __construct(private EmployeeStatusService $statusService) {}

    /**
     * @param  Carbon|null  $asOf  tanggal acuan periode; null = tanpa periode (hari ini)
     * @param  bool  $dailyWageOnly  true = hanya Karyawan Casual & Daily Worker
     * @return Collection<int, Employee>
     */
    public function get(?Carbon $asOf, bool $dailyWageOnly): Collection
    {
        return Employee::query()
            ->with(self::RELATIONS)
            ->when($dailyWageOnly, fn ($query) => $query->whereIn('employment_status', EmploymentStatus::dailyWageValues()))
            ->orderBy('name')
            ->get()
            ->filter(fn (Employee $employee) => $asOf === null
                ? $employee->active_status === EmployeeActiveStatus::Active->value
                : $this->statusService->statusOn($employee, $asOf) === EmployeeActiveStatus::Active)
            ->values();
    }
}
