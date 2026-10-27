<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\CostCenterRequest;
use App\Http\Resources\Payroll\CostCenterResource;
use App\Models\CostCenter;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Master Cost Center.
 *
 *   GET    /cost-centers          daftar (urut kode)
 *   POST   /cost-centers          tambah
 *   GET    /cost-centers/{id}     detail
 *   PUT    /cost-centers/{id}     ubah
 *   DELETE /cost-centers/{id}     hapus (ditolak kalau sudah dipakai di riwayat cost center karyawan)
 */
class CostCenterController extends Controller
{
    /**
     * Daftar cost center
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success(CostCenterResource::collection(CostCenter::query()->orderBy('code')->get()), 'Daftar cost center.');
    }

    /**
     * Tambah cost center
     */
    public function store(CostCenterRequest $request): JsonResponse
    {
        $costCenter = CostCenter::create($request->validated());

        return ApiResponse::success(CostCenterResource::make($costCenter), 'Cost center berhasil ditambahkan.', 201);
    }

    /**
     * Detail cost center
     */
    public function show(CostCenter $costCenter): JsonResponse
    {
        return ApiResponse::success(CostCenterResource::make($costCenter), 'Detail cost center.');
    }

    /**
     * Ubah cost center
     */
    public function update(CostCenterRequest $request, CostCenter $costCenter): JsonResponse
    {
        $costCenter->update($request->validated());

        return ApiResponse::success(CostCenterResource::make($costCenter), 'Cost center berhasil diperbarui.');
    }

    /**
     * Hapus cost center
     *
     * Ditolak (409) kalau cost center sudah dipakai di riwayat cost center karyawan.
     */
    public function destroy(CostCenter $costCenter): JsonResponse
    {
        $usageCount = $costCenter->employeeCostCenters()->count();

        if ($usageCount > 0) {
            abort(409, "Cost center \"{$costCenter->code}\" sudah dipakai di {$usageCount} riwayat cost center karyawan, jadi tidak bisa dihapus.");
        }

        $costCenter->delete();

        return ApiResponse::success(null, 'Cost center berhasil dihapus.');
    }
}
