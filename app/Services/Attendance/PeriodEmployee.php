<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeCostCenter;
use App\Models\EmployeeWageScheme;
use App\Models\PlacementObject;
use App\Models\PositionAssignment;
use Illuminate\Support\Carbon;

/**
 * Satu karyawan Casual / Daily Worker dalam satu periode absensi: tanggal-tanggal aktifnya, penempatan
 * (jabatan utama pada tanggal acuan), serta skema upah & cost center yang berlaku. Dibuat oleh AttendanceEmployees.
 */
class PeriodEmployee
{
    /** @var array<string, true> tanggal aktif "YYYY-MM-DD" => true */
    private array $activeDates;

    /** @param  list<string>  $activeDates */
    public function __construct(
        public readonly Employee $employee,
        array $activeDates,
        public readonly ?PositionAssignment $assignment,
        public readonly ?EmployeeWageScheme $scheme,
        public readonly ?EmployeeCostCenter $costCenter = null,
        /** Tanggal acuan penempatan, skema, cost center, & rekening (tanggal acuan periode atau tanggal aktif terakhir). */
        public readonly ?Carbon $referenceDate = null,
    ) {
        $this->activeDates = array_fill_keys($activeDates, true);
    }

    public function isActiveOn(string $date): bool
    {
        return isset($this->activeDates[$date]);
    }

    /** @return list<string> */
    public function activeDates(): array
    {
        return array_keys($this->activeDates);
    }

    /** Sub unit penempatan: { id, name, afdeling_code }, atau null. */
    public function subUnit(): ?array
    {
        $subUnit = $this->assignment?->subUnit;

        return $subUnit === null ? null : ['id' => $subUnit->id, 'name' => $subUnit->name, 'afdeling_code' => $subUnit->afdeling_code];
    }

    /** @return list<array{id: string, name: string}> */
    public function placementObjects(): array
    {
        return $this->assignment === null ? [] : $this->assignment->placementObjects
            ->map(fn (PlacementObject $object) => ['id' => $object->id, 'name' => $object->name])
            ->values()
            ->all();
    }

    /** Nama objek penempatan dipisah koma, mis. "Parkir, Tiket". */
    public function placementObjectNames(): string
    {
        return implode(', ', array_column($this->placementObjects(), 'name'));
    }

    /** Jabatan utama pada tanggal acuan, atau null kalau belum ditempatkan. */
    public function positionTitle(): ?string
    {
        return $this->assignment?->position->title;
    }

    /** Kelompok Daftar Upah dari skema upah yang berlaku, atau null. */
    public function payrollGroupName(): ?string
    {
        return $this->scheme?->payrollGroup?->name;
    }

    /**
     * Identitas upah untuk respons API (nilai pada tanggal acuan periode):
     *   position       jabatan utama, null = belum ditempatkan
     *   payroll_group  { id, name } dari skema upah yang berlaku, null = belum diatur / tanpa kelompok
     *   cost_center    { id, code, name } (id cost center master), null = belum diatur
     *
     * @return array{position: ?string, payroll_group: ?array{id: string, name: string}, cost_center: ?array{id: string, code: string, name: string}}
     */
    public function payrollIdentity(): array
    {
        $group = $this->scheme?->payrollGroup;
        $costCenter = $this->costCenter?->costCenter;

        return [
            'position' => $this->positionTitle(),
            'payroll_group' => $group === null ? null : ['id' => $group->id, 'name' => $group->name],
            'cost_center' => $costCenter === null ? null : ['id' => $costCenter->id, 'code' => $costCenter->code, 'name' => $costCenter->name],
        ];
    }

    /** Batas hari K dari skema upah (null = tidak dibatasi / belum diatur). */
    public function capDays(): ?int
    {
        return $this->scheme?->is_capped ? $this->scheme->cap_days : null;
    }

    /** Identitas ringkas untuk respons API. */
    public function summary(): array
    {
        return [
            'id' => $this->employee->id,
            'registration_number' => $this->employee->registration_number,
            'name' => $this->employee->name,
            'employment_status' => $this->employee->employment_status,
        ];
    }
}
