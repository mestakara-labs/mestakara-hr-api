<?php

namespace App\Services\Transfer;

use App\Models\PositionAssignment;
use App\Models\SupervisorChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Menghitung PENGALIHAN ATASAN OTOMATIS sebuah usulan mutasi, TANPA mengubah data.
 * Dipakai untuk perkiraan (sebelum berlaku) dan saat usulan diberlakukan.
 *
 * ATURAN
 *   Kalau pemegang jabatan X diakhiri penugasannya oleh usulan ini, bawahannya dialihkan:
 *     a. ke pemegang BARU jabatan X, kalau di usulan yang SAMA ada karyawan yang ditempatkan di X;
 *     b. kalau tidak, ke ATASAN dari pemegang lama (naik satu tingkat), mis. BRM-4 -> BRM-2.
 *   Pengalihan (b) dicatat dengan "jabatan yang kosong" (vacated_position_id), supaya nanti saat
 *   jabatan X terisi lagi, bawahannya bisa DIKEMBALIKAN ke pemegang baru ("Kembalikan bawahan").
 */
class SupervisorPlanner
{
    /**
     * @param  list<array{id: string, from_assignment_id: string, position_id: string, restore_subordinates: bool}>  $items
     */
    public function plan(array $items): SupervisorPlan
    {
        $assignments = $this->currentAssignments();
        $endedIds = array_values(array_filter(array_column($items, 'from_assignment_id')));

        // Rencana kosong dulu, supaya fungsi resolve() & newHoldersOf() bisa dipakai di bawah.
        $draft = new SupervisorPlan([], [], $endedIds, $assignments, $items);
        $changes = [];

        // Langkah 1: bawahan dari penugasan yang diakhiri usulan ini.
        foreach ($assignments as $assignment) {
            if (in_array($assignment->id, $endedIds, true)) {
                continue;
            }

            $supervisorIds = $assignment->supervisors->pluck('id')->all();
            $endedSupervisorIds = array_values(array_intersect($supervisorIds, $endedIds));
            if ($endedSupervisorIds === []) {
                continue;
            }

            // Atasan yang diakhiri dan jabatannya TIDAK diisi pemegang baru = jabatan kosong.
            $vacantId = collect($endedSupervisorIds)->first(
                fn (string $id) => $draft->newHoldersOf($assignments->get($id)?->position_id) === [],
            );

            $changes[] = [
                'assignment_id' => $assignment->id,
                'to_refs' => $draft->resolve($supervisorIds),
                'reason' => $vacantId
                    ? 'Atasan dimutasi dan jabatannya kosong, dialihkan ke atasan berikutnya'
                    : 'Atasan dimutasi, dialihkan ke pemegang baru jabatan tersebut',
                'vacated_position_id' => $vacantId ? $assignments->get($vacantId)?->position_id : null,
            ];
        }

        // Langkah 2: bawahan yang dulu dialihkan ke atas, dikembalikan ke pemegang baru.
        $changedIds = array_column($changes, 'assignment_id');
        $restorableCounts = [];

        foreach (array_unique(array_column($items, 'position_id')) as $positionId) {
            $candidates = $this->restorableSubordinates($positionId, $assignments, array_merge($endedIds, $changedIds));
            $restorableCounts[$positionId] = $candidates->count();

            $isRequested = collect($items)->contains(
                fn (array $item) => $item['position_id'] === $positionId && $item['restore_subordinates'],
            );
            if (! $isRequested) {
                continue;
            }

            foreach ($candidates as $candidate) {
                $changes[] = [
                    'assignment_id' => $candidate->id,
                    'to_refs' => $draft->newHoldersOf($positionId),
                    'reason' => 'Dikembalikan ke pemegang baru jabatan atasannya',
                    'vacated_position_id' => null,
                ];
                $changedIds[] = $candidate->id;
            }
        }

        return new SupervisorPlan($changes, $restorableCounts, $endedIds, $assignments, $items);
    }

    /**
     * Penugasan yang BELUM SELESAI (sedang berjalan atau akan datang), key = id.
     *
     * @return Collection<string, PositionAssignment>
     */
    private function currentAssignments(): Collection
    {
        $today = Carbon::today()->toDateString();

        return PositionAssignment::query()
            ->with('supervisors:id')
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }

    /**
     * Bawahan yang BISA dikembalikan ke pemegang baru jabatan $positionId:
     * perubahan atasan TERAKHIR-nya adalah "dialihkan ke atas karena jabatan ini kosong",
     * dan atasannya belum diubah lagi sejak itu.
     *
     * @param  list<string>  $excludedIds  penugasan yang sudah diakhiri / sudah diubah di rencana ini
     */
    private function restorableSubordinates(string $positionId, Collection $assignments, array $excludedIds): Collection
    {
        // Hanya penugasan yang PERNAH dialihkan ke atas karena jabatan ini kosong yang perlu diperiksa.
        $everVacatedIds = SupervisorChange::query()
            ->where('vacated_position_id', $positionId)
            ->distinct()
            ->pluck('assignment_id')
            ->all();

        return $assignments->filter(function (PositionAssignment $assignment) use ($positionId, $excludedIds, $everVacatedIds) {
            if (in_array($assignment->id, $excludedIds, true) || ! in_array($assignment->id, $everVacatedIds, true)) {
                return false;
            }

            $lastChange = SupervisorChange::query()
                ->with('newSupervisors:id')
                ->where('assignment_id', $assignment->id)
                ->orderByDesc('changed_at')
                ->orderByDesc('id')
                ->first();

            if ($lastChange?->vacated_position_id !== $positionId) {
                return false;
            }

            $current = $assignment->supervisors->pluck('id')->sort()->values()->all();
            $changedTo = $lastChange->newSupervisors->pluck('id')->sort()->values()->all();

            return $current === $changedTo;
        })->values();
    }
}
