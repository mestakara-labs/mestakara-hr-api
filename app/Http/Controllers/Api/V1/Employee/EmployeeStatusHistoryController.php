<?php

namespace App\Http\Controllers\Api\V1\Employee;

use App\Enums\ProposalStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeActivationItem;
use App\Models\EmployeeTerminationItem;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

/**
 * Riwayat Status Kepegawaian satu karyawan:
 *
 *   GET /employees/{id}/status-history
 *
 * Setiap kali diaktifkan (usulan Penambahan) atau dinonaktifkan (usulan Penonaktifan).
 * Usulan Draf tidak ikut; usulan Dibatalkan ikut (dengan statusnya). Terbaru di atas.
 */
class EmployeeStatusHistoryController extends Controller
{
    /**
     * Riwayat status kepegawaian
     */
    public function __invoke(Employee $employee): JsonResponse
    {
        $documentRelations = ['documentFile', 'signerAssignment.employee', 'signerAssignment.position'];

        $added = EmployeeActivationItem::query()
            ->with(['activation' => fn ($query) => $query->with($documentRelations), ...$documentRelations])
            ->where('employee_id', $employee->id)
            ->whereHas('activation', fn ($query) => $query->where('status', '!=', ProposalStatus::Draft->value))
            ->get()
            ->map(fn (EmployeeActivationItem $item) => [
                'kind' => 'Penambahan',
                'type' => (string) $item->type,
                'effective_date' => $item->effective_date?->toDateString() ?? '',
                'status' => $item->activation->status->value,
                'source_id' => $item->activation_id,
                'source_url' => "/dashboard/penambahan/{$item->activation_id}",
                'description' => $item->origin_unit ? "Unit asal: {$item->origin_unit}" : $item->notes,
                'document' => $this->document($item->activation, $item, 'employee-activations'),
            ]);

        $removed = EmployeeTerminationItem::query()
            ->with(['termination' => fn ($query) => $query->with($documentRelations), ...$documentRelations])
            ->where('employee_id', $employee->id)
            ->whereHas('termination', fn ($query) => $query->where('status', '!=', ProposalStatus::Draft->value))
            ->get()
            ->map(fn (EmployeeTerminationItem $item) => [
                'kind' => 'Penonaktifan',
                'type' => (string) $item->type,
                'effective_date' => $item->effective_date?->toDateString() ?? '',
                'status' => $item->termination->status->value,
                'source_id' => $item->termination_id,
                'source_url' => "/dashboard/penonaktifan/{$item->termination_id}",
                'description' => $item->destination_unit ? "Unit tujuan: {$item->destination_unit}" : ($item->reason ?? $item->notes),
                'document' => $this->document($item->termination, $item, 'employee-terminations'),
            ]);

        $events = $added->concat($removed)->sortByDesc('effective_date')->values();

        return ApiResponse::success($events, 'Riwayat status kepegawaian.');
    }

    /** Dokumen satu karyawan: kolektif milik usulan, atau per orang milik itemnya. */
    private function document(Model $proposal, Model $item, string $path): ?array
    {
        $baseUrl = config('hr.file_url_prefix')."/{$path}/{$proposal->id}/files";

        return $proposal->isCollective()
            ? $proposal->documentResource($baseUrl)
            : $item->documentResource("{$baseUrl}?item={$item->id}");
    }
}
