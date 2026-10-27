<?php

namespace App\Http\Resources\Assignment;

use App\Models\PositionAssignment;
use App\Models\SupervisorChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu catatan Riwayat Atasan, sesuai SupervisorChangeResource di front end (types/api/supervisor-change.ts).
 * Controller wajib memuat relasi di RELATIONS.
 *
 * @mixin SupervisorChange
 */
class SupervisorChangeResource extends JsonResource
{
    public const RELATIONS = [
        'changer',
        'previousSupervisors.employee', 'previousSupervisors.position',
        'newSupervisors.employee', 'newSupervisors.position',
    ];

    public function toArray(Request $request): array
    {
        $summary = fn (PositionAssignment $supervisor) => AssignmentResource::supervisorSummary($supervisor);

        return [
            'id' => $this->id,
            'assignment_id' => $this->assignment_id,
            /** Atasan sebelum diubah (kosong = tanpa atasan). */
            'from' => $this->previousSupervisors->map($summary),
            /** Atasan sesudah diubah (kosong = tanpa atasan). */
            'to' => $this->newSupervisors->map($summary),
            /** Waktu perubahan, format ISO 8601. */
            'changed_at' => $this->changed_at->toIso8601String(),
            /** Nama pengguna yang mengubah. */
            'changed_by' => $this->changer->name,
            /** "Manual", "Mutasi <nomor SK>", atau "Penonaktifan <nomor>". */
            'source' => $this->source,
            'notes' => $this->notes,
        ];
    }
}
