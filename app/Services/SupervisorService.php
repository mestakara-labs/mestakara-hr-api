<?php

namespace App\Services;

use App\Models\PositionAssignment;
use App\Models\SupervisorChange;

/**
 * SATU-SATUNYA tempat mengganti atasan langsung sebuah penugasan.
 *
 * Setiap penggantian SELALU dicatat di Riwayat Atasan (supervisor_changes + supervisor_change_details),
 * baik lewat tombol "Ubah Atasan" (sumber "Manual") maupun otomatis dari Mutasi / Penonaktifan.
 *
 *   $supervisorService->replace($assignment, [$atasanBaru->id], SupervisorChange::SOURCE_MANUAL, 'Reorganisasi');
 */
class SupervisorService
{
    /**
     * Ganti atasan penugasan, lalu catat di riwayat. Hasilnya catatan riwayatnya.
     *
     * @param  list<string>  $newSupervisorIds  id PENUGASAN atasan baru (kosong = tanpa atasan)
     * @param  array{transfer_id?: string, termination_id?: string, vacated_position_id?: string|null}  $origin
     *                                                                                                           usulan penyebabnya (kosong untuk Manual), dan jabatan atasan yang kosong
     *                                                                                                           kalau bawahan dialihkan ke atas
     */
    public function replace(
        PositionAssignment $assignment,
        array $newSupervisorIds,
        string $source,
        ?string $notes = null,
        array $origin = [],
    ): SupervisorChange {
        $previousIds = $assignment->supervisors()->pluck('position_assignments.id')->all();

        $change = SupervisorChange::create([
            'assignment_id' => $assignment->id,
            'source' => $source,
            'transfer_id' => $origin['transfer_id'] ?? null,
            'termination_id' => $origin['termination_id'] ?? null,
            'vacated_position_id' => $origin['vacated_position_id'] ?? null,
            'notes' => $notes,
            'changed_by' => auth()->id(),
            'changed_at' => now(),
        ]);

        $change->previousSupervisors()->attach($previousIds);
        $change->newSupervisors()->attach($newSupervisorIds);
        $assignment->supervisors()->sync($newSupervisorIds);

        return $change;
    }

    /** Apakah daftar atasan sekarang sudah sama dengan daftar baru? (urutan tidak berpengaruh) */
    public function hasSameSupervisors(PositionAssignment $assignment, array $newSupervisorIds): bool
    {
        $currentIds = $assignment->supervisors()->pluck('position_assignments.id')->sort()->values()->all();
        $newIds = collect($newSupervisorIds)->unique()->sort()->values()->all();

        return $currentIds === $newIds;
    }
}
