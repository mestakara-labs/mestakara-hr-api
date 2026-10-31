<?php

namespace App\Services\Dashboard;

use App\Models\Employee;
use App\Services\EmployeeStatusService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * FAKTA SEMUA KARYAWAN PADA SATU TANGGAL. Data karyawan & relasinya dimuat SEKALI, fakta per tanggal disimpan
 * (satu permintaan dashboard memakai beberapa tanggal: `to`, `from`, tanggal acuan tiap bulan).
 * Satu instance per permintaan (lihat AppServiceProvider::onePerRequest()).
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

    public function __construct(private EmployeeStatusService $statusService)
    {
        $this->statusService->rememberEvents();
    }

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
        $day = Carbon::parse($key);
        $employees = $this->employees();
        $facts = [];
        foreach ($employeeIds as $employeeId) {
            if ($employees->has($employeeId)) {
                $facts[$employeeId] = $this->factsByDate[$key][$employeeId] ??= new EmployeeFacts($employees->get($employeeId), $day, $this->statusService);
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

    /**
     * Semua karyawan beserta relasinya (dimuat sekali), dikunci id, urut nama. Dipakai juga kalkulator Daftar Upah
     * dashboard (lihat PayrollMonths) supaya karyawan tidak dimuat dua kali.
     *
     * @return Collection<string, Employee>
     */
    public function employees(): Collection
    {
        return $this->employees ??= Employee::query()->with(self::RELATIONS)->orderBy('name')->get()->keyBy('id');
    }
}
