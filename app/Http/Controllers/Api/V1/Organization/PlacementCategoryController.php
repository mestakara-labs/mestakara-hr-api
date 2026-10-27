<?php

namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\PlacementCategoryRequest;
use App\Http\Resources\Organization\PlacementCategoryResource;
use App\Models\PlacementCategory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Master Kategori Objek Penempatan.
 *
 *   GET    /placement-categories          daftar
 *   POST   /placement-categories          tambah
 *   GET    /placement-categories/{id}     detail
 *   PUT    /placement-categories/{id}     ubah
 *   DELETE /placement-categories/{id}     hapus (ditolak kalau masih dipakai objek)
 */
class PlacementCategoryController extends Controller
{
    /**
     * Daftar kategori objek
     */
    public function index(): JsonResponse
    {
        $categories = PlacementCategory::query()->withCount('placementObjects')->orderBy('id')->get();

        return ApiResponse::success(PlacementCategoryResource::collection($categories), 'Daftar kategori objek.');
    }

    /**
     * Tambah kategori objek
     */
    public function store(PlacementCategoryRequest $request): JsonResponse
    {
        $category = PlacementCategory::create($request->validated());

        return ApiResponse::success(PlacementCategoryResource::make($category->loadCount('placementObjects')), 'Kategori objek berhasil ditambahkan.', 201);
    }

    /**
     * Detail kategori objek
     */
    public function show(PlacementCategory $placementCategory): JsonResponse
    {
        return ApiResponse::success(PlacementCategoryResource::make($placementCategory->loadCount('placementObjects')), 'Detail kategori objek.');
    }

    /**
     * Ubah kategori objek
     */
    public function update(PlacementCategoryRequest $request, PlacementCategory $placementCategory): JsonResponse
    {
        $placementCategory->update($request->validated());

        return ApiResponse::success(PlacementCategoryResource::make($placementCategory->loadCount('placementObjects')), 'Kategori objek berhasil diperbarui.');
    }

    /**
     * Hapus kategori objek
     *
     * Ditolak (409) kalau kategori masih dipakai objek penempatan.
     */
    public function destroy(PlacementCategory $placementCategory): JsonResponse
    {
        $objectCount = $placementCategory->placementObjects()->count();

        if ($objectCount > 0) {
            abort(409, "Kategori \"{$placementCategory->name}\" masih dipakai oleh {$objectCount} objek penempatan. Pindahkan objeknya ke kategori lain terlebih dahulu.");
        }

        $placementCategory->delete();

        return ApiResponse::success(null, 'Kategori objek berhasil dihapus.');
    }
}
