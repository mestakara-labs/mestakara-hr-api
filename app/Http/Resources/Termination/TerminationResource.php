<?php

namespace App\Http\Resources\Termination;

use App\Enums\ProposalStatus;
use App\Models\EmployeeTermination;
use App\Models\EmployeeTerminationItem;
use App\Services\Termination\TerminationImpact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Usulan Penonaktifan, sesuai TerminationResource di front end (types/api/employee-termination.ts).
 * Controller wajib memuat relasi di RELATIONS.
 *
 * DAMPAK per karyawan: Draf = perkiraan, Berlaku = yang sudah diterapkan, Dibatalkan = null.
 *
 * @mixin EmployeeTermination
 */
class TerminationResource extends JsonResource
{
    public const RELATIONS = [
        'creator', 'canceller', 'documentFile', 'signerAssignment.employee', 'signerAssignment.position',
        'items.employee', 'items.documentFile', 'items.signerAssignment.employee', 'items.signerAssignment.position',
        'items.endedAssignments', 'items.endedMemberships.workGroup',
    ];

    public function toArray(Request $request): array
    {
        $isCollective = $this->isCollective();
        $missingDocumentCount = $isCollective
            ? ($this->hasDocumentFile() ? 0 : $this->items->count())
            : $this->items->reject(fn (EmployeeTerminationItem $item) => $item->hasDocumentFile())->count();

        $impact = app(TerminationImpact::class);
        $plannedImpacts = $this->status === ProposalStatus::Draft
            ? $impact->planned($this->items->map(fn (EmployeeTerminationItem $item) => [
                'id' => $item->id,
                'employee_id' => $item->employee_id,
                'effective_date' => $item->effective_date?->toDateString() ?? '',
            ])->all())
            : [];

        return [
            'id' => $this->id,
            /** Draf / Berlaku / Dibatalkan. */
            'status' => $this->status->value,
            /** Kolektif / Per Orang. */
            'document_scope' => $this->document_scope->value,
            'document' => $isCollective ? $this->documentResource($this->fileUrl(null)) : null,
            'notes' => $this->notes,
            'items' => $this->items->map(fn (EmployeeTerminationItem $item) => [
                'id' => $item->id,
                'employee' => [
                    'id' => $item->employee->id,
                    'name' => $item->employee->name,
                    'nik' => $item->employee->nik,
                    'photo_url' => $item->employee->photoUrl(),
                    'employment_status' => $item->employee->employment_status,
                    /** Status karyawan SEKARANG: Belum Aktif / Aktif / Nonaktif. */
                    'active_status' => $item->employee->active_status,
                ],
                /** Pensiun / Pensiun Dini / Mengundurkan Diri / PHK / Kontrak Berakhir / Mutasi Keluar / Meninggal Dunia. */
                'type' => (string) $item->type,
                /** TMT nonaktif (hari pertama tidak aktif), "" kalau belum diisi (Draf). */
                'effective_date' => $item->effective_date?->toDateString() ?? '',
                'reason' => $item->reason,
                'destination_unit' => $item->destination_unit,
                'document' => $isCollective ? null : $item->documentResource($this->fileUrl($item->id)),
                /** Perkiraan (Draf), yang diterapkan (Berlaku), atau null (Dibatalkan). */
                'impact' => match ($this->status) {
                    ProposalStatus::Effective => $impact->applied($this->resource, $item),
                    ProposalStatus::Draft => $plannedImpacts[$item->id] ?? null,
                    default => null,
                },
                'notes' => $item->notes,
            ])->values(),
            'missing_document_count' => $missingDocumentCount,
            'created_at' => $this->created_at->toIso8601String(),
            'created_by' => $this->creator->name,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => $this->canceller?->name,
            'cancel_reason' => $this->cancel_reason,
        ];
    }

    /** Alamat file dokumen lewat front end: kolektif (tanpa item) atau per orang. */
    private function fileUrl(?string $itemId): string
    {
        $query = $itemId ? "?item={$itemId}" : '';

        return config('hr.file_url_prefix')."/employee-terminations/{$this->id}/files{$query}";
    }
}
