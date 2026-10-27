<?php

namespace App\Http\Resources\Schedule;

use App\Enums\AssignmentPeriod;
use App\Models\WorkGroup;
use App\Models\WorkGroupMember;
use App\Services\Schedule\Rotation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Regu kerja + anggotanya, sesuai WorkGroupResource di front end (types/api/work-group.ts).
 * Controller wajib memuat relasi di RELATIONS.
 *
 * @mixin WorkGroup
 */
class WorkGroupResource extends JsonResource
{
    public const RELATIONS = [
        'subUnit', 'placementObject', 'workPattern.slots.days', 'leader', 'members.employee', 'members.startSlot',
    ];

    public function toArray(Request $request): array
    {
        $members = $this->sortedMembers()->map(fn (WorkGroupMember $member) => $this->member($member))->values();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'sub_unit' => ['id' => $this->subUnit->id, 'name' => $this->subUnit->name],
            'placement_object' => $this->placementObject ? ['id' => $this->placementObject->id, 'name' => $this->placementObject->name] : null,
            'work_pattern' => [
                'id' => $this->workPattern->id,
                'name' => $this->workPattern->name,
                'cycle_days' => $this->workPattern->cycle_days,
            ],
            'leader' => $this->leader ? ['employee_id' => $this->leader->id, 'name' => $this->leader->name] : null,
            /** Tetap / Bergilir. */
            'rotation_mode' => $this->rotation_mode->value,
            'rotate_every_days' => $this->rotate_every_days,
            /** Tanggal Mulai Berlaku Jadwal (hari ke-1 pola). */
            'anchor_date' => $this->anchor_date->toDateString(),
            'description' => $this->description,
            /** Semua anggota: yang belum selesai di atas, lalu riwayat. */
            'members' => $members,
            /** Jumlah anggota Aktif + Akan Datang. */
            'active_member_count' => $members->where('period', '!=', AssignmentPeriod::Finished->value)->count(),
            'slot_count' => $this->workPattern->slots->count(),
        ];
    }

    /** Anggota belum selesai di atas, lalu yang terbaru bergabung. */
    private function sortedMembers()
    {
        return $this->members->sortBy([
            fn (WorkGroupMember $a, WorkGroupMember $b) => ($a->period() === AssignmentPeriod::Finished) <=> ($b->period() === AssignmentPeriod::Finished),
            fn (WorkGroupMember $a, WorkGroupMember $b) => $b->start_date <=> $a->start_date,
        ]);
    }

    /** Satu anggota (GroupMemberResource). */
    private function member(WorkGroupMember $member): array
    {
        $today = Carbon::today()->toDateString();
        $period = $member->period();

        return [
            'id' => $member->id,
            'employee' => [
                'id' => $member->employee->id,
                'name' => $member->employee->name,
                'nik' => $member->employee->nik,
                'photo_url' => $member->employee->photoUrl(),
            ],
            /** Slot saat anggota bergabung. */
            'start_slot' => ['id' => $member->startSlot->id, 'code' => $member->startSlot->code, 'name' => $member->startSlot->name],
            'start_date' => $member->start_date->toDateString(),
            'end_date' => $member->end_date?->toDateString(),
            'is_active' => $period === AssignmentPeriod::Active,
            /** Akan Datang / Aktif / Selesai. */
            'period' => $period->value,
            /** Kode slot yang dipegang HARI INI (hasil rotasi); null = tidak aktif hari ini. */
            'current_slot_code' => Rotation::slotOn($this->resource, $this->workPattern->slots, $member, $today)?->code,
        ];
    }
}
