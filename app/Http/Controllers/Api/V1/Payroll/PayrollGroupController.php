<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\PayrollGroupRequest;
use App\Http\Resources\Payroll\PayrollGroupResource;
use App\Models\PayrollGroup;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Master Kelompok Daftar Upah.
 *
 *   GET    /payroll-groups          daftar (urut sort_order)
 *   POST   /payroll-groups          tambah
 *   GET    /payroll-groups/{id}     detail
 *   PUT    /payroll-groups/{id}     ubah
 *   DELETE /payroll-groups/{id}     hapus (ditolak kalau sudah dipakai di skema upah)
 */
class PayrollGroupController extends Controller
{
    /**
     * Daftar kelompok daftar upah
     */
    public function index(): JsonResponse
    {
        $groups = PayrollGroup::query()->orderBy('sort_order')->orderBy('name')->get();

        return ApiResponse::success(PayrollGroupResource::collection($groups), 'Daftar kelompok daftar upah.');
    }

    /**
     * Tambah kelompok daftar upah
     */
    public function store(PayrollGroupRequest $request): JsonResponse
    {
        $group = PayrollGroup::create([...$request->validated(), 'sort_order' => $request->validated('sort_order') ?? 0]);

        return ApiResponse::success(PayrollGroupResource::make($group), 'Kelompok daftar upah berhasil ditambahkan.', 201);
    }

    /**
     * Detail kelompok daftar upah
     */
    public function show(PayrollGroup $payrollGroup): JsonResponse
    {
        return ApiResponse::success(PayrollGroupResource::make($payrollGroup), 'Detail kelompok daftar upah.');
    }

    /**
     * Ubah kelompok daftar upah
     */
    public function update(PayrollGroupRequest $request, PayrollGroup $payrollGroup): JsonResponse
    {
        $payrollGroup->update([...$request->validated(), 'sort_order' => $request->validated('sort_order') ?? 0]);

        return ApiResponse::success(PayrollGroupResource::make($payrollGroup), 'Kelompok daftar upah berhasil diperbarui.');
    }

    /**
     * Hapus kelompok daftar upah
     *
     * Ditolak (409) kalau kelompok sudah dipakai di riwayat skema upah.
     */
    public function destroy(PayrollGroup $payrollGroup): JsonResponse
    {
        $usageCount = $payrollGroup->wageSchemes()->count();

        if ($usageCount > 0) {
            abort(409, "Kelompok \"{$payrollGroup->name}\" sudah dipakai di {$usageCount} riwayat skema upah, jadi tidak bisa dihapus.");
        }

        $payrollGroup->delete();

        return ApiResponse::success(null, 'Kelompok daftar upah berhasil dihapus.');
    }
}
