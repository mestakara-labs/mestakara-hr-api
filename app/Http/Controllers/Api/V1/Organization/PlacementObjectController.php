<?php

namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\PlacementObjectRequest;
use App\Http\Resources\Organization\PlacementObjectResource;
use App\Models\PlacementObject;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Master Objek Penempatan (objek bisa tersedia di beberapa sub unit).
 *
 *   GET    /placement-objects          daftar
 *   POST   /placement-objects          tambah
 *   GET    /placement-objects/{id}     detail
 *   PUT    /placement-objects/{id}     ubah
 *   DELETE /placement-objects/{id}     hapus (ditolak kalau pernah dipakai penugasan)
 */
class PlacementObjectController extends Controller
{
    /** Relasi yang dibutuhkan PlacementObjectResource. */
    private const RELATIONS = ['category', 'subUnits'];

    /**
     * Daftar objek penempatan
     */
    public function index(): JsonResponse
    {
        $objects = PlacementObject::query()
            ->with(self::RELATIONS)
            ->withCount($this->counts())
            ->orderBy('id')
            ->get();

        return ApiResponse::success(PlacementObjectResource::collection($objects), 'Daftar objek penempatan.');
    }

    /**
     * Tambah objek penempatan
     */
    public function store(PlacementObjectRequest $request): JsonResponse
    {
        $object = DB::transaction(function () use ($request) {
            $object = PlacementObject::create($request->safe()->except('sub_unit_ids'));
            $object->subUnits()->sync(array_unique($request->validated('sub_unit_ids')));

            return $object;
        });

        return ApiResponse::success($this->resource($object), 'Objek penempatan berhasil ditambahkan.', 201);
    }

    /**
     * Detail objek penempatan
     */
    public function show(PlacementObject $placementObject): JsonResponse
    {
        return ApiResponse::success($this->resource($placementObject), 'Detail objek penempatan.');
    }

    /**
     * Ubah objek penempatan
     *
     * Sub unit yang masih punya penugasan aktif di objek ini tidak boleh dilepas.
     */
    public function update(PlacementObjectRequest $request, PlacementObject $placementObject): JsonResponse
    {
        DB::transaction(function () use ($request, $placementObject) {
            $placementObject->update($request->safe()->except('sub_unit_ids'));
            $placementObject->subUnits()->sync(array_unique($request->validated('sub_unit_ids')));
        });

        return ApiResponse::success($this->resource($placementObject), 'Objek penempatan berhasil diperbarui.');
    }

    /**
     * Hapus objek penempatan
     *
     * Ditolak (409) kalau objek pernah dipakai penugasan jabatan (termasuk riwayat).
     */
    public function destroy(PlacementObject $placementObject): JsonResponse
    {
        $assignmentCount = $placementObject->assignments()->count();

        if ($assignmentCount > 0) {
            abort(409, "Objek \"{$placementObject->name}\" masih dipakai di {$assignmentCount} penugasan jabatan (termasuk riwayat). Ubah penugasannya terlebih dahulu.");
        }

        $placementObject->delete();

        return ApiResponse::success(null, 'Objek penempatan berhasil dihapus.');
    }

    /** Objek + relasi & jumlah yang dibutuhkan resource. */
    private function resource(PlacementObject $object): PlacementObjectResource
    {
        return PlacementObjectResource::make($object->load(self::RELATIONS)->loadCount($this->counts()));
    }

    /** Jumlah penugasan AKTIF di objek ini. */
    private function counts(): array
    {
        return ['assignments as active_assignments_count' => fn ($query) => $query->active()];
    }
}
