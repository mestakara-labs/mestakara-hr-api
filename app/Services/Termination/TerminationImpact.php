<?php

namespace App\Services\Termination;

use App\Enums\AssignmentPeriod;
use App\Http\Resources\Assignment\AssignmentResource;
use App\Models\EmployeeTermination;
use App\Models\EmployeeTerminationItem;
use App\Models\PositionAssignment;
use App\Models\SupervisorChange;
use App\Models\WorkGroupMember;
use App\Services\Transfer\SupervisorPlanner;
use Illuminate\Support\Carbon;

/**
 * DAMPAK penonaktifan per karyawan (TMT nonaktif = hari pertama tidak aktif):
 *
 *   1. Jabatan yang masih berjalan diakhiri: TMT selesai = sehari sebelum TMT nonaktif
 *   2. Bawahannya dialihkan ke ATASAN karyawan itu (naik satu tingkat), dengan aturan yang sama
 *      seperti Mutasi (SupervisorPlanner) — jabatannya tidak punya pemegang baru
 *   3. Keanggotaan regu kerja diakhiri: tanggal terakhir = sehari sebelum TMT nonaktif
 *
 * Bentuk satu dampak (TerminationImpact di front end):
 *   { ended_assignments: [...], subordinates: [...], memberships: [...] }
 */
class TerminationImpact
{
    public const REASON = 'Atasan dinonaktifkan, dialihkan ke atasan berikutnya';

    public function __construct(private SupervisorPlanner $planner) {}

    /**
     * Rencana dampak, TANPA mengubah data. Item tanpa TMT dilewati.
     *
     * @param  list<array{id: string, employee_id: string, effective_date: string}>  $items
     * @return array{assignment_ids: array<string, list<string>>, member_ids: array<string, list<string>>, changes: list<array>}
     */
    public function plan(array $items): array
    {
        $assignmentIds = [];
        $memberIds = [];
        foreach ($items as $item) {
            if ($item['effective_date'] !== '') {
                $assignmentIds[$item['id']] = $this->assignmentsToEnd($item['employee_id'], $item['effective_date']);
                $memberIds[$item['id']] = $this->membershipsToEnd($item['employee_id'], $item['effective_date']);
            }
        }

        // Pengalihan atasan: penugasan yang diakhiri TIDAK punya pemegang baru (position_id kosong),
        // jadi bawahannya naik ke atasan karyawan itu.
        $planItems = [];
        foreach ($assignmentIds as $itemId => $ids) {
            foreach ($ids as $id) {
                $planItems[] = ['id' => "{$itemId}:{$id}", 'from_assignment_id' => $id, 'position_id' => '', 'restore_subordinates' => false];
            }
        }

        $changes = array_map(
            fn (array $change) => [...$change, 'reason' => self::REASON],
            $this->planner->plan($planItems)->changes,
        );

        return ['assignment_ids' => $assignmentIds, 'member_ids' => $memberIds, 'changes' => $changes];
    }

    /**
     * PERKIRAAN dampak per item (Pratinjau Dampak & usulan Draf): [id item => dampak].
     *
     * @param  list<array{id: string, employee_id: string, effective_date: string}>  $items
     */
    public function planned(array $items): array
    {
        $plan = $this->plan($items);
        $impacts = [];

        foreach ($items as $item) {
            $endedIds = $plan['assignment_ids'][$item['id']] ?? [];
            $endDate = $item['effective_date'] !== '' ? Carbon::parse($item['effective_date'])->subDay()->toDateString() : '';

            $subordinates = [];
            foreach ($plan['changes'] as $change) {
                $current = PositionAssignment::find($change['assignment_id'])?->supervisors()->pluck('position_assignments.id')->all() ?? [];
                if (array_intersect($current, $endedIds) !== []) {
                    $subordinates[] = $this->subordinate($change['assignment_id'], $current, $change['to_refs']);
                }
            }

            $impacts[$item['id']] = [
                'ended_assignments' => array_map(fn (string $id) => $this->endedAssignment(PositionAssignment::find($id), $endDate), $endedIds),
                'subordinates' => $subordinates,
                'memberships' => array_map(
                    fn (string $id) => $this->membership(WorkGroupMember::with('workGroup')->find($id), $endDate),
                    $plan['member_ids'][$item['id']] ?? [],
                ),
            ];
        }

        return $impacts;
    }

    /** Dampak yang SUDAH diterapkan satu item usulan Berlaku. */
    public function applied(EmployeeTermination $termination, EmployeeTerminationItem $item): array
    {
        $endedIds = $item->endedAssignments->pluck('id')->all();

        $subordinates = $termination->supervisorChanges()
            ->where('source', $termination->supervisorChangeSource())
            ->with(['previousSupervisors:id', 'newSupervisors:id'])
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (SupervisorChange $change) => $change->previousSupervisors->pluck('id')->intersect($endedIds)->isNotEmpty())
            ->map(fn (SupervisorChange $change) => $this->subordinate(
                $change->assignment_id,
                $change->previousSupervisors->pluck('id')->all(),
                $change->newSupervisors->pluck('id')->all(),
            ))
            ->values()
            ->all();

        return [
            'ended_assignments' => $item->endedAssignments
                ->map(fn (PositionAssignment $assignment) => $this->endedAssignment($assignment, $assignment->end_date?->toDateString() ?? ''))
                ->all(),
            'subordinates' => $subordinates,
            'memberships' => $item->endedMemberships
                ->map(fn (WorkGroupMember $member) => $this->membership($member, $member->end_date?->toDateString() ?? ''))
                ->all(),
        ];
    }

    /**
     * Keanggotaan regu karyawan yang masih berjalan pada TMT nonaktif.
     *
     * @return list<string>
     */
    public function membershipsToEnd(string $employeeId, string $effectiveDate): array
    {
        return WorkGroupMember::query()
            ->where('employee_id', $employeeId)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $effectiveDate))
            ->pluck('id')
            ->all();
    }

    /** { member_id, group_id, group_name, end_date } */
    private function membership(?WorkGroupMember $member, string $endDate): array
    {
        $member?->loadMissing('workGroup');

        return [
            'member_id' => $member?->id,
            'group_id' => $member?->work_group_id ?? '',
            'group_name' => $member?->workGroup->name ?? '-',
            'end_date' => $endDate,
        ];
    }

    /**
     * Penugasan karyawan yang masih berjalan (atau akan datang) pada TMT nonaktif.
     *
     * @return list<string>
     */
    public function assignmentsToEnd(string $employeeId, string $effectiveDate): array
    {
        return PositionAssignment::query()
            ->where('employee_id', $employeeId)
            ->get()
            ->filter(fn (PositionAssignment $assignment) => $assignment->period() !== AssignmentPeriod::Finished
                && ($assignment->end_date === null || $assignment->end_date->toDateString() >= $effectiveDate))
            ->pluck('id')
            ->values()
            ->all();
    }

    /** { assignment_id, position_title, assignment_status, sub_unit_name, end_date } */
    private function endedAssignment(?PositionAssignment $assignment, string $endDate): array
    {
        $assignment?->loadMissing(['position', 'subUnit']);

        return [
            'assignment_id' => $assignment?->id,
            'position_title' => $assignment?->position->title ?? '-',
            'assignment_status' => $assignment?->assignment_status ?? '-',
            'sub_unit_name' => $assignment?->subUnit->name ?? '-',
            'end_date' => $endDate,
        ];
    }

    /** { assignment_id, employee_name, position_title, from: [...], to: [...] } */
    private function subordinate(string $assignmentId, array $fromIds, array $toIds): array
    {
        $assignment = PositionAssignment::with(['employee', 'position'])->find($assignmentId);
        $summaries = fn (array $ids) => PositionAssignment::with(['employee', 'position'])
            ->whereKey($ids)
            ->get()
            ->map(fn (PositionAssignment $supervisor) => AssignmentResource::supervisorSummary($supervisor))
            ->values();

        return [
            'assignment_id' => $assignmentId,
            'employee_name' => $assignment?->employee->name ?? '-',
            'position_title' => $assignment?->position->title ?? '-',
            'from' => $summaries($fromIds),
            'to' => $summaries($toIds),
        ];
    }
}
