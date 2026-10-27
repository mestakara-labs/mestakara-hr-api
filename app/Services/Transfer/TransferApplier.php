<?php

namespace App\Services\Transfer;

use App\Enums\TransferStatus;
use App\Models\PositionAssignment;
use App\Models\SupervisorChange;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Services\SupervisorService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * MENERAPKAN dan MEMBATALKAN usulan mutasi pada data penugasan:
 *
 *   apply()   usulan BERLAKU: penugasan baru dibuat, penugasan lama diakhiri, atasan bawahan dialihkan
 *   revert()  usulan Berlaku DIBATALKAN: semua perubahan di atas dikembalikan
 *
 * Dipanggil dari TransferService di dalam transaksi database.
 */
class TransferApplier
{
    public function __construct(
        private SupervisorPlanner $planner,
        private SupervisorService $supervisorService,
    ) {}

    /**
     * Usulan BERLAKU. Urutannya:
     *   1. Hitung rencana pengalihan atasan (dari data SEBELUM diubah)
     *   2. Buat penugasan baru setiap karyawan (berlaku mulai TMT)
     *   3. Akhiri penugasan lama (TMT selesai = sehari sebelum TMT baru)
     *   4. Alihkan atasan bawahan (tercatat di Riwayat Atasan dengan sumber "Mutasi <nomor SK>")
     */
    public function apply(Transfer $transfer): void
    {
        $transfer->load(['items.placementObjects', 'items.supervisors']);
        $effectiveDate = $transfer->effective_date;

        // 1. Rencana
        $plan = $this->planner->plan($transfer->toPayload()['items']);

        // 2. Penugasan baru. Id-nya dibuat dulu, supaya rujukan "baru:<id item>" bisa diganti id sungguhan.
        $newIdOfItem = $transfer->items->mapWithKeys(fn (TransferItem $item) => [$item->id => (string) Str::uuid7()]);
        $toRealId = fn (string $ref) => $newIdOfItem[SupervisorPlan::itemIdOfRef($ref)] ?? $ref;

        foreach ($transfer->items as $item) {
            $assignment = new PositionAssignment([
                'employee_id' => $item->employee_id,
                'position_id' => $item->position_id,
                'sub_unit_id' => $item->sub_unit_id,
                'assignment_status' => $item->assignment_status,
                'start_date' => $effectiveDate,
                'end_date' => $item->end_date,
                'decree_type' => 'SK',
                'decree_number' => $transfer->decree_number,
                'decree_date' => $transfer->decree_date,
                // Petikan SK karyawan ini (kalau sudah diunggah) menggantikan scan SK kolektif.
                'decree_file_id' => $item->excerpt_file_id ?? $transfer->signed_file_id,
                'transfer_id' => $transfer->id,
                'notes' => $item->notes,
            ]);
            $assignment->id = $newIdOfItem[$item->id];
            $assignment->save();

            $item->update(['new_assignment_id' => $assignment->id]);
        }

        // Objek & atasan dipasang SETELAH semua penugasan baru ada (atasan bisa sesama penugasan baru).
        foreach ($transfer->items as $item) {
            $assignment = $item->newAssignment;
            $assignment->placementObjects()->sync($item->placementObjects->pluck('id'));
            $assignment->supervisors()->sync(array_map($toRealId, $plan->resolve($item->supervisors->pluck('id')->all())));
        }

        // 3. Penugasan lama diakhiri sehari sebelum TMT baru.
        foreach ($transfer->items as $item) {
            $oldAssignment = $item->fromAssignment;
            if ($oldAssignment === null) {
                continue;
            }

            $transfer->endedAssignments()->attach($oldAssignment->id, [
                'previous_end_date' => $oldAssignment->end_date?->toDateString(),
            ]);
            $oldAssignment->update(['end_date' => $effectiveDate->copy()->subDay()]);
        }

        // 4. Atasan bawahan dialihkan.
        foreach ($plan->changes as $change) {
            $this->supervisorService->replace(
                PositionAssignment::findOrFail($change['assignment_id']),
                array_map($toRealId, $change['to_refs']),
                $transfer->supervisorChangeSource(),
                $change['reason'],
                ['transfer_id' => $transfer->id, 'vacated_position_id' => $change['vacated_position_id']],
            );
        }

        $transfer->update([
            'status' => TransferStatus::Effective,
            'activated_at' => now(),
        ]);
    }

    /**
     * Usulan Berlaku DIBATALKAN: semua perubahannya dikembalikan.
     *   1. Atasan bawahan dikembalikan (tercatat di Riwayat Atasan)
     *   2. Penugasan lama aktif lagi (TMT selesai sebelumnya dipulihkan)
     *   3. Penugasan baru dihapus
     *
     * Hasilnya null kalau berhasil, atau PESAN ALASAN kalau pembatalan ditolak:
     *   - karyawan di usulan ini sudah dimutasi lagi lewat usulan lain,
     *   - atasan yang diubah usulan ini sudah diubah lagi oleh usulan lain yang lebih baru, atau
     *   - ada karyawan lain yang SEKARANG beratasan penugasan baru dari usulan ini, bukan karena usulan ini.
     */
    public function revert(Transfer $transfer, string $reason): ?string
    {
        $createdIds = $transfer->items()->whereNotNull('new_assignment_id')->pluck('new_assignment_id')->all();
        $ourChanges = $transfer->supervisorChanges()->with(['previousSupervisors:id', 'newSupervisors:id'])->orderBy('changed_at')->orderBy('id')->get();

        $blockedReason = $this->revertBlockedReason($transfer, $createdIds, $ourChanges);
        if ($blockedReason !== null) {
            return $blockedReason;
        }

        $source = trim('Pembatalan Mutasi '.$transfer->decree_number);

        // 1. Atasan dikembalikan (dari perubahan terakhir ke yang pertama).
        foreach ($ourChanges->reverse() as $change) {
            if (in_array($change->assignment_id, $createdIds, true)) {
                continue;
            }

            $assignment = PositionAssignment::find($change->assignment_id);
            // Kalau atasannya sudah diubah lagi setelah itu, perubahan yang lebih baru dibiarkan.
            if ($assignment === null || ! $this->hasSupervisors($assignment, $change->newSupervisors->pluck('id')->all())) {
                continue;
            }

            $this->supervisorService->replace(
                $assignment,
                $change->previousSupervisors->pluck('id')->all(),
                $source,
                $reason,
                ['transfer_id' => $transfer->id],
            );
        }

        // 2. Penugasan lama aktif lagi.
        foreach ($transfer->endedAssignments as $ended) {
            $ended->update(['end_date' => $ended->pivot->previous_end_date]);
        }
        $transfer->endedAssignments()->detach();

        // 3. Penugasan baru dihapus (riwayat atasannya ikut terhapus).
        PositionAssignment::whereIn('id', $createdIds)->get()->each->delete();

        return null;
    }

    /** Alasan pembatalan ditolak, atau null kalau boleh dibatalkan. */
    private function revertBlockedReason(Transfer $transfer, array $createdIds, Collection $ourChanges): ?string
    {
        // Pemeriksaan 1: sudah dimutasi lagi?
        $laterTransfer = Transfer::query()
            ->whereKeyNot($transfer->id)
            ->where('status', '!=', TransferStatus::Cancelled->value)
            ->whereHas('items', fn ($query) => $query->whereIn('from_assignment_id', $createdIds))
            ->first();

        if ($laterTransfer !== null) {
            $number = $laterTransfer->decree_number ?? 'lain (Draf)';

            return "Karyawan di usulan ini sudah dimutasi lagi lewat usulan {$number}. Batalkan usulan itu terlebih dahulu.";
        }

        // Pemeriksaan 2: atasan yang diubah usulan ini sudah diubah lagi oleh usulan LAIN yang lebih baru?
        foreach ($ourChanges as $ours) {
            $laterChangeTransfer = SupervisorChange::query()
                ->where('assignment_id', $ours->assignment_id)
                ->where('changed_at', '>', $ours->changed_at)
                ->whereNotNull('transfer_id')
                ->where('transfer_id', '!=', $transfer->id)
                ->with('transfer')
                ->get()
                ->map->transfer
                ->first(fn (?Transfer $other) => $other !== null && $other->status !== TransferStatus::Cancelled);

            if ($laterChangeTransfer !== null) {
                $number = $laterChangeTransfer->decree_number ?? 'lain';

                return "Atasan beberapa karyawan sudah diubah lagi oleh usulan {$number}. Batalkan usulan itu terlebih dahulu.";
            }
        }

        // Pemeriksaan 3: ada yang beratasan penugasan baru, bukan karena usulan ini?
        $subordinates = PositionAssignment::with(['employee', 'supervisors:id'])
            ->findMany(PositionAssignment::subordinateIdsOf($createdIds));

        foreach ($subordinates as $subordinate) {
            $ourChange = $ourChanges->last(fn (SupervisorChange $change) => $change->assignment_id === $subordinate->id);

            if ($ourChange === null || ! $this->hasSupervisors($subordinate, $ourChange->newSupervisors->pluck('id')->all())) {
                return "{$subordinate->employee->name} sekarang beratasan karyawan dari usulan ini. Ubah atasannya lewat \"Ubah Atasan\" terlebih dahulu.";
            }
        }

        return null;
    }

    /** Apakah atasan penugasan ini SAMA PERSIS dengan daftar id (urutan tidak berpengaruh)? */
    private function hasSupervisors(PositionAssignment $assignment, array $supervisorIds): bool
    {
        return $this->supervisorService->hasSameSupervisors($assignment, $supervisorIds);
    }
}
