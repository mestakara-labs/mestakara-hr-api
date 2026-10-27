<?php

namespace App\Http\Resources\Activation;

use App\Http\Resources\Assignment\AssignmentResource;
use App\Models\EmployeeActivation;
use App\Models\EmployeeActivationItem;
use App\Models\PositionAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Usulan Penambahan, sesuai ActivationResource di front end (types/api/employee-activation.ts).
 * Controller wajib memuat relasi di RELATIONS.
 *
 * @mixin EmployeeActivation
 */
class ActivationResource extends JsonResource
{
    public const RELATIONS = [
        'creator', 'canceller', 'documentFile', 'signerAssignment.employee', 'signerAssignment.position',
        'items.employee.educations', 'items.documentFile', 'items.signerAssignment.employee', 'items.signerAssignment.position',
        'items.position', 'items.subUnit', 'items.placementObjects', 'items.supervisors.employee', 'items.supervisors.position',
    ];

    public function toArray(Request $request): array
    {
        $isCollective = $this->isCollective();
        $missingDocumentCount = $isCollective
            ? ($this->hasDocumentFile() ? 0 : $this->items->count())
            : $this->items->reject(fn (EmployeeActivationItem $item) => $item->hasDocumentFile())->count();

        return [
            'id' => $this->id,
            /** Draf / Berlaku / Dibatalkan. */
            'status' => $this->status->value,
            /** Kolektif / Per Orang. */
            'document_scope' => $this->document_scope->value,
            /** Dokumen kolektif (hanya kalau cakupannya Kolektif). */
            'document' => $isCollective ? $this->documentResource($this->fileUrl(null)) : null,
            'notes' => $this->notes,
            'items' => $this->items->map(fn (EmployeeActivationItem $item) => $this->item($item, $isCollective))->values(),
            /** Jumlah karyawan yang belum punya dokumen (kolektif kosong = semua). */
            'missing_document_count' => $missingDocumentCount,
            'created_at' => $this->created_at->toIso8601String(),
            'created_by' => $this->creator->name,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => $this->canceller?->name,
            'cancel_reason' => $this->cancel_reason,
        ];
    }

    /** Satu karyawan di usulan (ActivationItemResource). */
    private function item(EmployeeActivationItem $item, bool $isCollective): array
    {
        $employee = $item->employee;

        return [
            'id' => $item->id,
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'nik' => $employee->nik,
                'photo_url' => $employee->photoUrl(),
                'employment_status' => $employee->employment_status,
                /** Status karyawan SEKARANG: Belum Aktif / Aktif / Nonaktif. */
                'active_status' => $employee->active_status,
                /** Isian wajib biodata yang belum diisi (kosong = Lengkap). */
                'missing_fields' => $employee->missingFields(),
            ],
            /** Penerimaan Baru / Mutasi Masuk / Pengaktifan Kembali. */
            'type' => (string) $item->type,
            /** TMT aktif, "" kalau belum diisi (Draf). */
            'effective_date' => $item->effective_date?->toDateString() ?? '',
            'origin_unit' => $item->origin_unit,
            /** Dokumen per orang (hanya kalau cakupannya Per Orang). */
            'document' => $isCollective ? null : $item->documentResource($this->fileUrl($item->id)),
            'placement' => $this->placement($item),
            'new_assignment_id' => $item->new_assignment_id,
            'notes' => $item->notes,
        ];
    }

    /** Rencana penempatan (PlacementPlanResource), atau null kalau belum ditempatkan. */
    private function placement(EmployeeActivationItem $item): ?array
    {
        if ($item->position_id === null) {
            return null;
        }

        return [
            'position_id' => $item->position_id,
            'position_title' => $item->position->title,
            'brm_level' => $item->position->brm_level,
            'job_grade' => $item->position->job_grade,
            'assignment_status' => (string) $item->assignment_status,
            'sub_unit_id' => (string) $item->sub_unit_id,
            'sub_unit_name' => $item->subUnit?->name ?? '-',
            'placement_object_ids' => $item->placementObjects->pluck('id'),
            'placement_object_names' => $item->placementObjects->pluck('name'),
            'supervisors' => $item->supervisors->map(fn (PositionAssignment $supervisor) => AssignmentResource::supervisorSummary($supervisor)),
            'end_date' => $item->assignment_end_date?->toDateString(),
        ];
    }

    /** Alamat file dokumen lewat front end: kolektif (tanpa item) atau per orang. */
    private function fileUrl(?string $itemId): string
    {
        $query = $itemId ? "?item={$itemId}" : '';

        return config('hr.file_url_prefix')."/employee-activations/{$this->id}/files{$query}";
    }
}
