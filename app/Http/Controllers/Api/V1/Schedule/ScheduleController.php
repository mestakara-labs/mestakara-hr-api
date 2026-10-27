<?php

namespace App\Http\Controllers\Api\V1\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Resources\Schedule\SchedulePeriodResource;
use App\Models\Schedule;
use App\Models\ScheduleChange;
use App\Services\Schedule\SchedulePeriodService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Satu sel jadwal (satu karyawan pada satu tanggal).
 *
 *   PUT /schedules/{id}           ubah shift { shift_id ("" = libur), reason (wajib kalau Terbit) }
 *   GET /schedules/{id}/changes   riwayat perubahan sel (terbaru di atas)
 */
class ScheduleController extends Controller
{
    /**
     * Ubah satu sel jadwal
     */
    public function update(Request $request, Schedule $schedule, SchedulePeriodService $periodService): JsonResponse
    {
        $schedule = $periodService->updateCell($schedule, $request->input('shift_id'), $request->input('reason'));

        return ApiResponse::success(SchedulePeriodResource::entry($schedule->load(['employee', 'changes'])), 'Jadwal berhasil diubah.');
    }

    /**
     * Riwayat perubahan sel
     */
    public function changes(Schedule $schedule): JsonResponse
    {
        $changes = $schedule->changes()
            ->with('changer')
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ScheduleChange $change) => [
                'id' => $change->id,
                'old_shift_id' => $change->old_shift_id,
                'new_shift_id' => $change->new_shift_id,
                'reason' => $change->reason,
                'changed_by' => $change->changer->name,
                'changed_at' => $change->changed_at->toIso8601String(),
            ]);

        return ApiResponse::success($changes, 'Riwayat perubahan jadwal.');
    }
}
