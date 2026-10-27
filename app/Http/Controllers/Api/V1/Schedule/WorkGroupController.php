<?php

namespace App\Http\Controllers\Api\V1\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\WorkGroupRequest;
use App\Http\Resources\Schedule\WorkGroupResource;
use App\Models\WorkGroup;
use App\Models\WorkGroupMember;
use App\Services\Schedule\Rotation;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Regu Kerja.
 *
 *   GET    /work-groups                              daftar
 *   POST   /work-groups                              tambah (+ anggota baru sekaligus)
 *   GET    /work-groups/{id}                         detail (+ anggota)
 *   PUT    /work-groups/{id}                         ubah (+ tambah anggota baru)
 *   DELETE /work-groups/{id}                         hapus (ditolak kalau pernah punya anggota)
 *   GET    /work-groups/{id}/members                 daftar anggota
 *   PUT    /work-groups/{id}/members/{memberId}      akhiri keanggotaan { end_date }
 *   GET    /work-groups/{id}/projection?from=&to=    proyeksi jadwal (dihitung, tidak disimpan; maks. 62 hari)
 */
class WorkGroupController extends Controller
{
    /** Batas rentang proyeksi. */
    private const MAX_PROJECTION_DAYS = 62;

    /**
     * Daftar regu kerja
     */
    public function index(): JsonResponse
    {
        $groups = WorkGroup::query()->with(WorkGroupResource::RELATIONS)->orderBy('name')->get();

        return ApiResponse::success(WorkGroupResource::collection($groups), 'Daftar regu kerja.');
    }

    /**
     * Tambah regu kerja
     *
     * Anggota baru (`members`) ikut disimpan, bergabung pada `members_start_date`.
     */
    public function store(WorkGroupRequest $request): JsonResponse
    {
        $group = $this->save(new WorkGroup, $request);

        return ApiResponse::success($this->resource($group), 'Regu kerja berhasil ditambahkan'.$this->memberCountText($request).'.', 201);
    }

    /**
     * Detail regu kerja
     */
    public function show(WorkGroup $workGroup): JsonResponse
    {
        return ApiResponse::success($this->resource($workGroup), 'Detail regu kerja.');
    }

    /**
     * Ubah regu kerja
     *
     * Anggota lama tidak diubah; anggota baru (`members`) ditambahkan.
     */
    public function update(WorkGroupRequest $request, WorkGroup $workGroup): JsonResponse
    {
        $group = $this->save($workGroup, $request);

        return ApiResponse::success($this->resource($group), 'Regu kerja berhasil diperbarui'.$this->memberCountText($request).'.');
    }

    /**
     * Hapus regu kerja
     *
     * Regu yang pernah punya anggota tidak boleh dihapus (riwayat).
     */
    public function destroy(WorkGroup $workGroup): JsonResponse
    {
        $memberCount = $workGroup->members()->count();

        if ($memberCount > 0) {
            abort(409, "Regu \"{$workGroup->name}\" sudah punya {$memberCount} riwayat anggota, jadi tidak bisa dihapus.");
        }

        $workGroup->delete();

        return ApiResponse::success(null, 'Regu kerja berhasil dihapus.');
    }

    /**
     * Daftar anggota regu
     */
    public function members(WorkGroup $workGroup): JsonResponse
    {
        return ApiResponse::success($this->resource($workGroup)->toArray(request())['members'], 'Anggota regu kerja.');
    }

    /**
     * Akhiri keanggotaan
     *
     * Body JSON: `{ end_date }` = tanggal terakhir di regu (tidak boleh sebelum tanggal bergabung).
     */
    public function endMember(Request $request, WorkGroup $workGroup, WorkGroupMember $member): JsonResponse
    {
        $endDate = (string) $request->input('end_date');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            throw ValidationException::withMessages(['end_date' => 'Pilih Tanggal Terakhir']);
        }
        if ($endDate < $member->start_date->toDateString()) {
            throw ValidationException::withMessages(['end_date' => 'Tanggal Terakhir tidak boleh sebelum tanggal bergabung']);
        }

        $member->update(['end_date' => $endDate]);

        return ApiResponse::success($this->resource($workGroup), 'Keanggotaan berhasil diakhiri.');
    }

    /**
     * Proyeksi jadwal regu
     *
     * `?from=YYYY-MM-DD&to=YYYY-MM-DD` (maks. 62 hari). Dihitung dari rumus rotasi, TIDAK disimpan,
     * beserta slot kosong yang punya shift.
     */
    public function projection(Request $request, WorkGroup $workGroup): JsonResponse
    {
        $from = (string) $request->query('from');
        $to = (string) $request->query('to');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
            return ApiResponse::error('Rentang tanggal belum benar.', 422);
        }
        if (Rotation::daysBetween($from, $to) >= self::MAX_PROJECTION_DAYS) {
            return ApiResponse::error('Rentang proyeksi maksimal '.self::MAX_PROJECTION_DAYS.' hari.', 422);
        }

        $workGroup->load(['workPattern.slots.days', 'members.employee']);
        $slots = $workGroup->workPattern->slots;
        $cycleDays = $workGroup->workPattern->cycle_days;
        $dates = Rotation::datesBetween($from, $to);
        $members = $workGroup->members->filter(
            fn (WorkGroupMember $member) => $member->start_date->toDateString() <= $to
                && ($member->end_date === null || $member->end_date->toDateString() >= $from),
        );

        $rows = $members->map(fn (WorkGroupMember $member) => [
            'member_id' => $member->id,
            'employee' => ['id' => $member->employee->id, 'name' => $member->employee->name],
            'cells' => array_map(function (string $date) use ($workGroup, $slots, $cycleDays, $member) {
                $result = Rotation::shiftOn($workGroup, $slots, $cycleDays, $member, $date);

                return ['date' => $date, 'slot_code' => $result['slot']->code ?? null, 'shift_id' => $result['shift_id'] ?? null];
            }, $dates),
        ])->values();

        // Sebelum Tanggal Mulai Berlaku Jadwal, regu belum berjalan -> tidak ada shift kosong.
        $vacancies = [];
        foreach ($dates as $date) {
            if ($date < $workGroup->anchor_date->toDateString()) {
                continue;
            }
            foreach (Rotation::vacancies($workGroup, $slots, $cycleDays, $members, $date) as $vacancy) {
                if ($vacancy['shift_id'] !== null) {
                    $vacancies[] = ['date' => $date, 'slot_code' => $vacancy['slot']->code, 'shift_id' => $vacancy['shift_id']];
                }
            }
        }

        return ApiResponse::success(
            ['from' => $from, 'to' => $to, 'dates' => $dates, 'rows' => $rows, 'vacancies' => $vacancies],
            'Proyeksi jadwal regu.',
        );
    }

    /** Simpan regu + anggota baru dalam satu transaksi. */
    private function save(WorkGroup $group, WorkGroupRequest $request): WorkGroup
    {
        return DB::transaction(function () use ($group, $request) {
            $group->fill($request->groupData())->save();

            foreach ($request->input('members', []) as $member) {
                $group->members()->create([
                    'employee_id' => $member['employee_id'],
                    'start_slot_id' => $member['start_slot_id'],
                    'start_date' => $request->input('members_start_date'),
                ]);
            }

            return $group;
        });
    }

    /** ", dengan 3 anggota baru" atau "" kalau tidak ada anggota baru. */
    private function memberCountText(WorkGroupRequest $request): string
    {
        $count = count($request->input('members', []));

        return $count > 0 ? ", dengan {$count} anggota baru" : '';
    }

    private function resource(WorkGroup $group): WorkGroupResource
    {
        return WorkGroupResource::make($group->load(WorkGroupResource::RELATIONS));
    }
}
