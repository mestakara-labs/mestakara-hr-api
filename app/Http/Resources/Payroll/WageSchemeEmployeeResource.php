<?php

namespace App\Http\Resources\Payroll;

use App\Models\Employee;
use App\Models\PlacementObject;
use App\Support\DataPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Satu baris daftar skema upah (GET /wage-schemes): karyawan Casual / DW + penempatan + data dasar upah
 * yang BERLAKU pada tanggal acuan. Controller wajib memuat ActivePayrollEmployees::RELATIONS.
 *
 * @mixin Employee
 */
class WageSchemeEmployeeResource extends JsonResource
{
    /** Tanggal acuan; null = hari ini. */
    private ?Carbon $asOf = null;

    public function asOf(?Carbon $date): static
    {
        $this->asOf = $date;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $date = $this->asOf ?? DataPeriod::asOfOrToday(null);
        $assignment = $this->mainAssignment($date);
        $scheme = $this->wageSchemeOn($date);

        return [
            'id' => $this->id,
            'registration_number' => $this->registration_number,
            'name' => $this->name,
            'employment_status' => $this->employment_status,

            // --- Penempatan (dari jabatan utama pada tanggal acuan) ---
            /** Jabatan utama, atau null kalau belum ditempatkan. */
            'position' => $assignment?->position->title,
            /** { id, name, afdeling_code }, atau null. */
            'sub_unit' => $assignment?->subUnit === null ? null : [
                'id' => $assignment->subUnit->id,
                'name' => $assignment->subUnit->name,
                'afdeling_code' => $assignment->subUnit->afdeling_code,
            ],
            /** [{ id, name }] */
            'placement_objects' => $assignment === null ? [] : $assignment->placementObjects
                ->map(fn (PlacementObject $object) => ['id' => $object->id, 'name' => $object->name])
                ->values(),

            // --- Data dasar upah yang berlaku ---
            /** Skema upah; null = "Belum diatur". */
            'wage_scheme' => $scheme === null ? null : WageSchemeResource::make($scheme),
            /** Upah sehari (Gaji Pokok / HKE, dibulatkan); null kalau skema belum diatur. */
            'daily_rate' => $scheme?->dailyRate(),
            'bank_account' => EmployeeBankAccountResource::summary($this->bankAccountOn($date)),
            'cost_center' => EmployeeCostCenterResource::summary($this->costCenterOn($date)),
        ];
    }
}
