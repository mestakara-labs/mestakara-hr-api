<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Enums\EmploymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\DashboardRequest;
use App\Models\CostCenter;
use App\Models\Division;
use App\Models\PayrollGroup;
use App\Models\PlacementCategory;
use App\Models\PlacementObject;
use App\Models\SubUnit;
use App\Services\Dashboard\DashboardWidgets;
use App\Services\Dashboard\Dimensions;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Dashboard HR (Tahap A: ringkasan, komposisi karyawan, upah Casual & DW).
 *
 *   GET /dashboard                  semua widget (periode sama, tanpa filter)
 *   GET /dashboard/widgets/{key}    satu widget, boleh pakai filter & group_by
 *   GET /dashboard/filter-options   pilihan filter
 *
 * Periode: from & to (YYYY-MM-DD), bulan utuh; bawaan 1 Januari tahun berjalan s.d. hari ini.
 * Service dashboard menyimpan hasil hitungan selama satu permintaan, jadi diterima per method (bukan lewat constructor,
 * karena instance controller bisa dipakai ulang oleh router untuk permintaan berikutnya).
 */
class DashboardController extends Controller
{
    /**
     * Semua widget dashboard
     */
    public function index(DashboardRequest $request, DashboardWidgets $widgets): JsonResponse
    {
        $period = $request->period();

        return ApiResponse::success([
            'from' => $period->from->toDateString(),
            'to' => $period->to->toDateString(),
            'period_label' => $period->label(),
            'widgets' => array_values($widgets->all($period)),
        ], 'Dashboard.');
    }

    /**
     * Satu widget dashboard
     */
    public function widget(DashboardRequest $request, DashboardWidgets $widgets, string $key): JsonResponse
    {
        if (! DashboardWidgets::exists($key)) {
            return ApiResponse::error('Widget tidak ditemukan.', 404);
        }

        $groupBy = $request->input('group_by');
        $choices = DashboardWidgets::GROUP_BY[$key] ?? [];
        if ($groupBy !== null && ! in_array($groupBy, $choices, true)) {
            throw ValidationException::withMessages(['group_by' => $choices === []
                ? 'Widget ini tidak memakai group_by'
                : 'group_by hanya boleh: '.implode(', ', $choices)]);
        }

        $widget = $widgets->one($key, $request->period(), $request->filters(), $groupBy);

        return ApiResponse::success($widget, 'Widget dashboard.');
    }

    /**
     * Pilihan filter dashboard & analitik
     */
    public function filterOptions(): JsonResponse
    {
        return ApiResponse::success([
            'sub_units' => SubUnit::query()->orderBy('name')->get(['id', 'name']),
            'divisions' => Division::query()->orderBy('name')->get(['id', 'name']),
            'segments' => PlacementCategory::query()->orderBy('name')->get(['id', 'name']),
            'placement_objects' => PlacementObject::query()->orderBy('name')->get(['id', 'name', 'category_id'])
                ->map(fn (PlacementObject $object) => ['id' => $object->id, 'name' => $object->name, 'segment_id' => $object->category_id]),
            'cost_centers' => CostCenter::query()->orderBy('code')->get(['id', 'code', 'name']),
            'payroll_groups' => PayrollGroup::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'employment_statuses' => array_map(
                fn (EmploymentStatus $status) => ['id' => $status->value, 'name' => Dimensions::statusLabel($status->value)],
                EmploymentStatus::cases(),
            ),
        ], 'Pilihan filter dashboard.');
    }
}
