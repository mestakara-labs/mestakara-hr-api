<?php

namespace App\Http\Controllers\Api\V1\Assignment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assignment\ChangeSupervisorsRequest;
use App\Http\Resources\Assignment\AssignmentResource;
use App\Http\Resources\Assignment\SupervisorChangeResource;
use App\Models\PositionAssignment;
use App\Models\SupervisorChange;
use App\Services\SupervisorService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ubah Atasan (tanpa SK) dan Riwayat Atasan.
 *
 *   POST /position-assignments/supervisors                 ubah atasan beberapa penugasan sekaligus
 *   GET  /position-assignments/{id}/supervisor-history     riwayat atasan satu penugasan
 */
class SupervisorController extends Controller
{
    public function __construct(private SupervisorService $supervisorService) {}

    /**
     * Ubah atasan
     *
     * Atasan baru berlaku untuk SEMUA penugasan yang dipilih. Penugasan yang atasannya
     * sudah sama dilewati (tidak dicatat di riwayat).
     */
    public function update(ChangeSupervisorsRequest $request): JsonResponse
    {
        $newSupervisorIds = array_values(array_unique($request->validated('supervisor_assignment_ids')));

        $changed = $request->targets->reject(
            fn (PositionAssignment $target) => $this->supervisorService->hasSameSupervisors($target, $newSupervisorIds),
        );

        if ($changed->isEmpty()) {
            throw ValidationException::withMessages([
                'supervisor_assignment_ids' => 'Atasan yang dipilih sama dengan atasan sekarang',
            ]);
        }

        DB::transaction(function () use ($changed, $newSupervisorIds, $request) {
            foreach ($changed as $target) {
                $this->supervisorService->replace(
                    $target,
                    $newSupervisorIds,
                    SupervisorChange::SOURCE_MANUAL,
                    $request->validated('notes'),
                );
            }
        });

        $changed->load(AssignmentResource::RELATIONS);

        return ApiResponse::success(AssignmentResource::collection($changed->values()), "Atasan {$changed->count()} karyawan berhasil diubah.");
    }

    /**
     * Riwayat atasan
     *
     * Terbaru di atas.
     */
    public function history(PositionAssignment $positionAssignment): JsonResponse
    {
        $history = $positionAssignment->supervisorChanges()
            ->with(SupervisorChangeResource::RELATIONS)
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(SupervisorChangeResource::collection($history), 'Riwayat atasan.');
    }
}
