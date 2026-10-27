<?php

namespace App\Services\Transfer;

use App\Enums\TransferStatus;
use App\Models\Employee;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\SupervisorChange;
use App\Models\Transfer;

/**
 * Ringkasan PERUBAHAN ATASAN OTOMATIS sebuah usulan, untuk front end (TransferSupervisorChange):
 *
 *   {
 *     assignment_id, employee_name, position_title,     <- bawahan yang atasannya berubah
 *     from: [{ name, position_title, is_new }],          <- atasan sebelum
 *     to:   [{ name, position_title, is_new }],          <- atasan sesudah (is_new = penugasan baru dari usulan)
 *     reason
 *   }
 *
 *   - Berlaku          -> yang benar-benar terjadi (dari Riwayat Atasan)
 *   - Draf / Menunggu  -> PERKIRAAN, dihitung dari data sekarang
 *   - Dibatalkan       -> kosong
 */
class TransferSupervisorChanges
{
    public function __construct(private SupervisorPlanner $planner) {}

    /** Perubahan atasan sebuah usulan tersimpan. */
    public function forTransfer(Transfer $transfer): array
    {
        if ($transfer->status === TransferStatus::Cancelled) {
            return [];
        }

        if ($transfer->status === TransferStatus::Effective) {
            return $this->applied($transfer);
        }

        $transfer->loadMissing(['items.placementObjects', 'items.supervisors']);

        return $this->preview($transfer->toPayload()['items'])['supervisor_changes'];
    }

    /**
     * Perkiraan untuk isian yang belum disimpan pun bisa (POST /transfers/preview).
     *
     * @param  list<array{id: string, employee_id: string, from_assignment_id: string, position_id: string, restore_subordinates: bool}>  $items
     * @return array{supervisor_changes: list<array>, restorable_counts: array<string, int>}
     */
    public function preview(array $items): array
    {
        $plan = $this->planner->plan($items);
        $people = $this->people(array_merge(
            array_column($plan->changes, 'assignment_id'),
            ...array_map(fn (array $change) => $this->currentSupervisorIds($change['assignment_id']), $plan->changes),
            ...array_column($plan->changes, 'to_refs'),
        ));

        /** Rujukan rencana -> orang. "baru:<id item>" = penugasan baru dari usulan ini. */
        $refToPerson = function (string $ref) use ($items, $people) {
            $itemId = SupervisorPlan::itemIdOfRef($ref);
            if ($itemId === null) {
                return [...($people[$ref] ?? $this->unknown()), 'is_new' => false];
            }

            $item = collect($items)->firstWhere('id', $itemId);

            return [
                'name' => Employee::find($item['employee_id'] ?? null)?->name ?? '-',
                'position_title' => Position::find($item['position_id'] ?? null)?->title ?? '-',
                'is_new' => true,
            ];
        };

        $changes = array_map(fn (array $change) => [
            'assignment_id' => $change['assignment_id'],
            'employee_name' => $people[$change['assignment_id']]['name'] ?? '-',
            'position_title' => $people[$change['assignment_id']]['position_title'] ?? '-',
            'from' => array_map(
                fn (string $id) => [...($people[$id] ?? $this->unknown()), 'is_new' => false],
                $this->currentSupervisorIds($change['assignment_id']),
            ),
            'to' => array_map($refToPerson, $change['to_refs']),
            'reason' => $change['reason'],
        ], $plan->changes);

        return ['supervisor_changes' => $changes, 'restorable_counts' => (object) $plan->restorableCounts];
    }

    /** Perubahan yang benar-benar terjadi saat usulan berlaku (dari Riwayat Atasan). */
    private function applied(Transfer $transfer): array
    {
        $createdIds = $transfer->items()->whereNotNull('new_assignment_id')->pluck('new_assignment_id')->all();
        $person = fn (PositionAssignment $assignment) => [
            'name' => $assignment->employee->name,
            'position_title' => $assignment->position->title,
            'is_new' => in_array($assignment->id, $createdIds, true),
        ];

        return $transfer->supervisorChanges()
            ->where('source', $transfer->supervisorChangeSource())
            ->with([
                'assignment.employee', 'assignment.position',
                'previousSupervisors.employee', 'previousSupervisors.position',
                'newSupervisors.employee', 'newSupervisors.position',
            ])
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get()
            ->map(fn (SupervisorChange $change) => [
                'assignment_id' => $change->assignment_id,
                'employee_name' => $change->assignment->employee->name,
                'position_title' => $change->assignment->position->title,
                'from' => $change->previousSupervisors->map($person)->values(),
                'to' => $change->newSupervisors->map($person)->values(),
                'reason' => $change->notes ?? '',
            ])
            ->all();
    }

    /** Atasan sebuah penugasan saat ini (id penugasan). */
    private function currentSupervisorIds(string $assignmentId): array
    {
        return PositionAssignment::find($assignmentId)?->supervisors()->pluck('position_assignments.id')->all() ?? [];
    }

    /**
     * Nama & jabatan beberapa penugasan sekaligus: [id => ['name' => ..., 'position_title' => ...]].
     * Rujukan "baru:..." dilewati.
     */
    private function people(array $assignmentIds): array
    {
        $ids = array_filter(array_unique($assignmentIds), fn (string $id) => SupervisorPlan::itemIdOfRef($id) === null);

        return PositionAssignment::with(['employee', 'position'])
            ->whereKey($ids)
            ->get()
            ->mapWithKeys(fn (PositionAssignment $assignment) => [$assignment->id => [
                'name' => $assignment->employee->name,
                'position_title' => $assignment->position->title,
            ]])
            ->all();
    }

    private function unknown(): array
    {
        return ['name' => '-', 'position_title' => '-'];
    }
}
