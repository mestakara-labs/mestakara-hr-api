<?php

namespace App\Services\Attendance;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Services\EmployeeStatusService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * KARYAWAN YANG DIHITUNG dalam satu periode absensi = Casual & Daily Worker yang AKTIF minimal satu hari
 * dalam periode itu (status kepegawaian per tanggal, aturan sama dengan Periode Data).
 */
class AttendanceEmployees
{
    private const RELATIONS = [
        'activationItems.activation', 'terminationItems.termination',
        'assignments.position', 'assignments.subUnit', 'assignments.placementObjects',
        'wageSchemes.payrollGroup', 'costCenterHistories.costCenter',
    ];

    public function __construct(private EmployeeStatusService $statusService) {}

    /**
     * Semua karyawan yang dihitung, urut nama, dikunci dengan id karyawan.
     *
     * @return Collection<string, PeriodEmployee>
     */
    public function forPeriod(AttendancePeriod $period): Collection
    {
        return Employee::query()
            ->with(self::RELATIONS)
            ->whereIn('employment_status', EmploymentStatus::dailyWageValues())
            ->orderBy('name')
            ->get()
            ->map(fn (Employee $employee) => $this->make($employee, $period))
            ->filter(fn (PeriodEmployee $item) => $item->activeDates() !== [])
            ->keyBy(fn (PeriodEmployee $item) => $item->employee->id);
    }

    /** Satu karyawan (apa pun statusnya) dalam periode itu; tanggal aktifnya bisa kosong. */
    public function one(Employee $employee, AttendancePeriod $period): PeriodEmployee
    {
        return $this->make($employee->loadMissing(self::RELATIONS), $period);
    }

    private function make(Employee $employee, AttendancePeriod $period): PeriodEmployee
    {
        $activeDates = $this->statusService->activeDatesBetween($employee, $period->start(), $period->end());

        // Penempatan, skema, & cost center: pada tanggal acuan, atau tanggal aktif terakhir kalau sudah nonaktif sebelumnya.
        $reference = $period->referenceDate();
        $lastActive = $activeDates === [] ? null : Carbon::parse(end($activeDates));
        if ($lastActive !== null && $lastActive->lessThan($reference)) {
            $reference = $lastActive;
        }

        return new PeriodEmployee(
            $employee,
            $activeDates,
            $employee->mainAssignment($reference),
            $employee->wageSchemeOn($reference),
            $employee->costCenterOn($reference),
            $reference,
        );
    }
}
