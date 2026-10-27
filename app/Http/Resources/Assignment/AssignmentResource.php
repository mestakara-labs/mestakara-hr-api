<?php

namespace App\Http\Resources\Assignment;

use App\Models\PlacementObject;
use App\Models\PositionAssignment;
use App\Services\FileStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Penugasan jabatan, sesuai AssignmentResource di front end (types/api/assignment.ts).
 * Controller wajib memuat relasi di AssignmentResource::RELATIONS.
 *
 * @mixin PositionAssignment
 */
class AssignmentResource extends JsonResource
{
    /** Relasi yang dibutuhkan resource ini (pakai dengan ->with() / ->load()). */
    public const RELATIONS = [
        'employee', 'position.division', 'subUnit', 'placementObjects.category',
        'supervisors.employee', 'supervisors.position', 'decreeFile', 'transfer',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => [
                'id' => $this->employee->id,
                'name' => $this->employee->name,
                'nik' => $this->employee->nik,
                'photo_url' => $this->employee->photoUrl(),
            ],
            'position' => [
                'id' => $this->position->id,
                'code' => $this->position->code,
                'title' => $this->position->title,
                'brm_level' => $this->position->brm_level,
                'job_grade' => $this->position->job_grade,
                'division_name' => $this->position->division?->name,
            ],
            'sub_unit' => ['id' => $this->subUnit->id, 'name' => $this->subUnit->name],
            /** Objek penempatan (boleh lebih dari satu, boleh kosong). */
            'placement_objects' => $this->placementObjects->map(fn (PlacementObject $object) => [
                'id' => $object->id,
                'name' => $object->name,
                'category_name' => $object->category->name,
            ]),
            /** Definitif / Penugasan / Pj / Plt / Plh / Ptr. */
            'assignment_status' => $this->assignment_status,
            /** Atasan langsung (penugasan milik atasan), boleh lebih dari satu. */
            'supervisors' => $this->supervisors->map(fn (PositionAssignment $supervisor) => self::supervisorSummary($supervisor)),
            /** TMT mulai, YYYY-MM-DD. */
            'start_date' => $this->start_date->toDateString(),
            /** TMT selesai; null = masih berjalan. */
            'end_date' => $this->end_date?->toDateString(),
            'is_active' => $this->isActive(),
            /** Akan Datang / Aktif / Selesai. */
            'period' => $this->period()->value,
            /** Usulan mutasi pembuatnya; null = data awal / dari Penambahan. */
            'transfer' => $this->transfer === null ? null : [
                'id' => $this->transfer->id,
                'decree_number' => $this->transfer->decree_number ?? '-',
            ],
            'decree_type' => $this->decree_type,
            'decree_number' => $this->decree_number,
            'decree_date' => $this->decree_date?->toDateString(),
            'decree_file' => FileStorage::info($this->decreeFile),
            'decree_file_url' => $this->decree_file_id
                ? config('hr.file_url_prefix')."/position-assignments/{$this->id}/decree-file"
                : null,
            'notes' => $this->notes,
        ];
    }

    /**
     * Ringkasan satu atasan: { assignment_id, employee_name, position_title, assignment_status }.
     * Butuh relasi employee & position di penugasan atasan.
     */
    public static function supervisorSummary(PositionAssignment $supervisor): array
    {
        return [
            'assignment_id' => $supervisor->id,
            'employee_name' => $supervisor->employee->name,
            'position_title' => $supervisor->position->title,
            'assignment_status' => $supervisor->assignment_status,
        ];
    }
}
