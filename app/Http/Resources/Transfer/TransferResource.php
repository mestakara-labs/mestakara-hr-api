<?php

namespace App\Http\Resources\Transfer;

use App\Http\Resources\Assignment\AssignmentResource;
use App\Models\PositionAssignment;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Services\FileStorage;
use App\Services\Transfer\TransferSupervisorChanges;
use App\Support\StatusDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Usulan mutasi, sesuai TransferResource di front end (types/api/transfer.ts).
 * Controller wajib memuat relasi di RELATIONS.
 *
 * @mixin Transfer
 */
class TransferResource extends JsonResource
{
    public const RELATIONS = [
        'creator', 'canceller', 'basisDocumentFile', 'signedFile',
        'signerAssignment.employee', 'signerAssignment.position',
        'basisSignerAssignment.employee', 'basisSignerAssignment.position',
        'items.employee', 'items.position.division', 'items.subUnit', 'items.placementObjects',
        'items.supervisors.employee', 'items.supervisors.position', 'items.excerptFile',
        'items.fromAssignment.position', 'items.fromAssignment.subUnit', 'items.fromAssignment.placementObjects',
    ];

    public function toArray(Request $request): array
    {
        $date = fn ($value) => $value?->toDateString();

        return [
            'id' => $this->id,
            /** Penempatan Awal / Rotasi / Promosi / Demosi / Penugasan Sementara. */
            'type' => $this->type->value,
            /** Draf / Menunggu Dokumen / Berlaku / Dibatalkan. */
            'status' => $this->status->value,
            'effective_date' => $date($this->effective_date),
            'notes' => $this->notes,

            // --- Dokumen dasar (opsional) ---
            'basis_document_type' => $this->basis_document_type,
            'basis_document_number' => $this->basis_document_number,
            'basis_document_date' => $date($this->basis_document_date),
            'basis_document_file' => FileStorage::info($this->basisDocumentFile),
            'basis_document_file_url' => $this->basis_document_file_id ? $this->documentUrl('type=dasar') : null,
            'basis_document_signer' => StatusDocument::signerResource(
                $this->basis_signer_source, $this->basisSignerAssignment, $this->basis_signer_name, $this->basis_signer_position,
            ),

            // --- SK ---
            'decree_number' => $this->decree_number,
            'decree_date' => $date($this->decree_date),
            'signer' => StatusDocument::signerResource(
                $this->signer_source, $this->signerAssignment, $this->signer_name, $this->signer_position,
            ),
            /** Unggah / Template. */
            'document_mode' => $this->document_mode?->value,
            'signed_file' => FileStorage::info($this->signedFile),
            'signed_file_url' => $this->signed_file_id ? $this->documentUrl('type=scan') : null,

            'items' => $this->items->map(fn (TransferItem $item) => $this->item($item))->values(),
            /** Perubahan atasan otomatis: perkiraan (belum berlaku) atau yang terjadi (Berlaku). */
            'supervisor_changes' => app(TransferSupervisorChanges::class)->forTransfer($this->resource),

            'created_at' => $this->created_at->toIso8601String(),
            'created_by' => $this->creator->name,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => $this->canceller?->name,
            'cancel_reason' => $this->cancel_reason,
        ];
    }

    /** Satu karyawan di usulan (TransferItemResource). */
    private function item(TransferItem $item): array
    {
        $from = $item->fromAssignment;
        $position = $item->position;

        return [
            'id' => $item->id,
            'employee' => [
                'id' => $item->employee->id,
                'name' => $item->employee->name,
                'nik' => $item->employee->nik,
                'photo_url' => $item->employee->photoUrl(),
            ],
            /** Penugasan lama yang diakhiri; null = tidak ada. */
            'from' => $from === null ? null : [
                'assignment_id' => $from->id,
                'position_title' => $from->position->title,
                'brm_level' => $from->position->brm_level,
                'job_grade' => $from->position->job_grade,
                'assignment_status' => $from->assignment_status,
                'sub_unit_name' => $from->subUnit->name,
                'placement_object_names' => $from->placementObjects->pluck('name'),
            ],
            /** Penugasan baru. */
            'to' => [
                'position_id' => (string) $item->position_id,
                'position_title' => $position?->title ?? '-',
                'brm_level' => $position?->brm_level ?? 6,
                'job_grade' => $position?->job_grade,
                'division_name' => $position?->division?->name,
                'assignment_status' => (string) $item->assignment_status,
                'sub_unit_id' => (string) $item->sub_unit_id,
                'sub_unit_name' => $item->subUnit?->name ?? '-',
                'placement_object_ids' => $item->placementObjects->pluck('id'),
                'placement_object_names' => $item->placementObjects->pluck('name'),
                'supervisors' => $item->supervisors->map(fn (PositionAssignment $supervisor) => AssignmentResource::supervisorSummary($supervisor)),
                'end_date' => $item->end_date?->toDateString(),
            ],
            'notes' => $item->notes,
            'restore_subordinates' => $item->restore_subordinates,
            /** Scan petikan SK karyawan ini (menggantikan SK kolektif untuk karyawan ini). */
            'excerpt_file' => FileStorage::info($item->excerptFile),
            'excerpt_file_url' => $item->excerpt_file_id ? $this->documentUrl("type=scan-petikan&item={$item->id}") : null,
            'new_assignment_id' => $item->new_assignment_id,
        ];
    }

    /** Alamat dokumen usulan lewat front end, mis. "/api/transfers/{id}/documents?type=scan". */
    private function documentUrl(string $query): string
    {
        return config('hr.file_url_prefix')."/transfers/{$this->id}/documents?{$query}";
    }
}
