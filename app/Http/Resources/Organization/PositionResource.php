<?php

namespace App\Http\Resources\Organization;

use App\Models\Position;
use App\Models\PositionAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Jabatan + pemegangnya, sesuai PositionResource di front end (types/api/organization.ts).
 * Dipakai di /positions dan /organization. Controller wajib memuat relasi di RELATIONS.
 *
 * @mixin Position
 */
class PositionResource extends JsonResource
{
    public const RELATIONS = [
        'division',
        'activeAssignments.employee',
        'activeAssignments.subUnit',
        'activeAssignments.placementObjects',
        'activeAssignments.supervisors',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'division_id' => $this->division_id,
            'division_name' => $this->division?->name,
            /** 1 = Manager (tertinggi) sampai 6 = Pelaksana. */
            'brm_level' => $this->brm_level,
            /** 1–99, null = belum diisi. */
            'job_grade' => $this->job_grade,
            'description' => $this->description,
            /**
             * Pegawai yang memegang jabatan ini, dari penugasan AKTIF (bisa lebih dari satu).
             * Garis atasan di bagan digambar per orang dari supervisor_assignment_ids.
             */
            'employees' => $this->activeAssignments->map(fn (PositionAssignment $assignment) => $this->holder($assignment))->values(),
        ];
    }

    /** Satu pemegang jabatan (EmployeeResource di types/api/organization.ts). */
    private function holder(PositionAssignment $assignment): array
    {
        $employee = $assignment->employee;

        return [
            'id' => $employee->id,
            'assignment_id' => $assignment->id,
            'assignment_status' => $assignment->assignment_status,
            'nik' => $employee->nik ?? '-',
            'name' => $employee->name,
            'email' => $employee->email ?? '',
            'phone' => $employee->phone ?? '',
            /** Sama dengan phone kalau karyawan tidak punya nomor WA tersendiri. */
            'whatsapp' => $employee->whatsapp ?? $employee->phone ?? '',
            'sub_unit_name' => $assignment->subUnit->name,
            'placement_object_names' => $assignment->placementObjects->pluck('name'),
            // Atasan yang penugasannya sudah selesai tidak digambar lagi di bagan.
            'supervisor_assignment_ids' => $assignment->supervisors
                ->filter(fn (PositionAssignment $supervisor) => $supervisor->isActive())
                ->pluck('id')
                ->values(),
        ];
    }
}
