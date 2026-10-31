<?php

namespace App\Services\Dashboard;

use App\Models\Employee;
use App\Models\PlacementObject;
use App\Services\EmployeeStatusService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * FAKTA SEMUA KARYAWAN PADA SATU TANGGAL. Data karyawan & relasinya dimuat SEKALI, fakta per tanggal disimpan
 * (satu permintaan dashboard memakai beberapa tanggal: `to`, `from`, tanggal acuan tiap bulan).
 * Daftarkan sebagai scoped binding (lihat AppServiceProvider) supaya satu permintaan memakai satu instance.
 */
class WorkforceFacts
{
    private const RELATIONS = [
        'activationItems.activation', 'terminationItems.termination',
        'assignments.position.division', 'assignments.subUnit', 'assignments.placementObjects.category',
        'educations', 'wageSchemes.payrollGroup', 'costCenterHistories.costCenter', 'bankAccounts',
    ];

    /** @var Collection<string, Employee>|null semua karyawan, dikunci id, urut nama */
    private ?Collection $employees = null;

    /** @var array<string, array<string, EmployeeFacts>> [tanggal => [id karyawan => fakta]] */
    private array $factsByDate = [];

    public function __construct(private EmployeeStatusService $statusService) {}

    /**
     * Fakta semua karyawan (apa pun statusnya) pada tanggal itu, dikunci id karyawan.
     *
     * @return array<string, EmployeeFacts>
     */
    public function on(Carbon $date): array
    {
        return $this->forEmployees($date, $this->employees()->keys()->all());
    }

    /**
     * Fakta beberapa karyawan saja pada tanggal itu (mis. Casual & DW di Daftar Upah), dikunci id karyawan.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, EmployeeFacts>
     */
    public function forEmployees(Carbon $date, array $employeeIds): array
    {
        $key = $date->toDateString();
        $employees = $this->employees();
        $facts = [];
        foreach ($employeeIds as $employeeId) {
            if ($employees->has($employeeId)) {
                $facts[$employeeId] = $this->factsByDate[$key][$employeeId] ??= $this->make($employees->get($employeeId), Carbon::parse($key));
            }
        }

        return $facts;
    }

    /**
     * Karyawan AKTIF pada tanggal itu.
     *
     * @return array<string, EmployeeFacts>
     */
    public function activeOn(Carbon $date): array
    {
        return array_filter($this->on($date), fn (EmployeeFacts $facts) => $facts->isActive());
    }

    /** @return Collection<string, Employee> */
    private function employees(): Collection
    {
        return $this->employees ??= Employee::query()->with(self::RELATIONS)->orderBy('name')->get()->keyBy('id');
    }

    private function make(Employee $employee, Carbon $date): EmployeeFacts
    {
        $main = $employee->mainAssignment($date);
        $placementObjects = [];
        $segments = [];
        foreach ($main?->placementObjects ?? [] as $object) {
            /** @var PlacementObject $object */
            $placementObjects[$object->id] = $object->name;
            if ($object->category !== null) {
                $segments[$object->category->id] = $object->category->name;
            }
        }
        $costCenter = $employee->costCenterOn($date)?->costCenter;
        $payrollGroup = $employee->hasDailyWage() ? $employee->wageSchemeOn($date)?->payrollGroup : null;

        return new EmployeeFacts(
            employee: $employee,
            date: $date,
            status: $this->statusService->statusOn($employee, $date),
            divisionId: $main?->position->division?->id,
            divisionName: $main?->position->division?->name,
            subUnitId: $main?->subUnit?->id,
            subUnitName: $main?->subUnit?->name,
            placementObjects: $placementObjects,
            segments: $segments,
            costCenter: $costCenter === null ? null : ['id' => $costCenter->id, 'code' => $costCenter->code, 'name' => $costCenter->name],
            payrollGroup: $payrollGroup === null ? null : ['id' => $payrollGroup->id, 'name' => $payrollGroup->name],
            brmLevel: $main?->position->brm_level,
            jobGrade: $main?->position->job_grade,
            age: $employee->birth_date === null || $employee->birth_date->greaterThan($date) ? null : (int) $employee->birth_date->diffInYears($date),
            education: $employee->highestEducation()?->level ?? '-',
            countServiceMonths: fn () => $this->statusService->serviceMonths($employee, $date),
            retirementDate: $employee->retirementDate($main),
            hasWageScheme: $employee->wageSchemeOn($date) !== null,
            hasBankAccount: $employee->bankAccountOn($date) !== null,
        );
    }
}
