<?php

namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\SubUnitRequest;
use App\Http\Resources\Organization\SubUnitResource;
use App\Models\SubUnit;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Master Sub Unit (afdeling).
 *
 *   GET    /sub-units          daftar
 *   POST   /sub-units          tambah
 *   GET    /sub-units/{id}     detail
 *   PUT    /sub-units/{id}     ubah
 *   DELETE /sub-units/{id}     hapus (ditolak kalau masih dipakai objek penempatan / penugasan)
 */
class SubUnitController extends Controller
{
    /**
     * Daftar sub unit
     */
    public function index(): JsonResponse
    {
        $subUnits = SubUnit::query()->withCount($this->counts())->orderBy('id')->get();

        return ApiResponse::success(SubUnitResource::collection($subUnits), 'Daftar sub unit.');
    }

    /**
     * Tambah sub unit
     */
    public function store(SubUnitRequest $request): JsonResponse
    {
        $subUnit = SubUnit::create($request->validated());

        return ApiResponse::success(SubUnitResource::make($subUnit->loadCount($this->counts())), 'Sub unit berhasil ditambahkan.', 201);
    }

    /**
     * Detail sub unit
     */
    public function show(SubUnit $subUnit): JsonResponse
    {
        return ApiResponse::success(SubUnitResource::make($subUnit->loadCount($this->counts())), 'Detail sub unit.');
    }

    /**
     * Ubah sub unit
     */
    public function update(SubUnitRequest $request, SubUnit $subUnit): JsonResponse
    {
        $subUnit->update($request->validated());

        return ApiResponse::success(SubUnitResource::make($subUnit->loadCount($this->counts())), 'Sub unit berhasil diperbarui.');
    }

    /**
     * Hapus sub unit
     *
     * Ditolak (409) kalau sub unit masih tercantum di objek penempatan, atau pernah dipakai penugasan jabatan.
     */
    public function destroy(SubUnit $subUnit): JsonResponse
    {
        $objectNames = $subUnit->placementObjects()->pluck('name');

        if ($objectNames->isNotEmpty()) {
            abort(409, "Sub unit \"{$subUnit->name}\" masih tercantum di objek penempatan: {$objectNames->join(', ')}. Lepaskan sub unit ini dari objek-objek tersebut terlebih dahulu.");
        }

        $assignmentCount = $subUnit->assignments()->count();

        if ($assignmentCount > 0) {
            abort(409, "Sub unit \"{$subUnit->name}\" masih dipakai di {$assignmentCount} penugasan jabatan (termasuk riwayat). Pindahkan penugasannya terlebih dahulu.");
        }

        $subUnit->delete();

        return ApiResponse::success(null, 'Sub unit berhasil dihapus.');
    }

    /** Jumlah yang dibutuhkan SubUnitResource: objek penempatan & penugasan AKTIF. */
    private function counts(): array
    {
        return [
            'placementObjects',
            'assignments as active_assignments_count' => fn ($query) => $query->active(),
        ];
    }
}
