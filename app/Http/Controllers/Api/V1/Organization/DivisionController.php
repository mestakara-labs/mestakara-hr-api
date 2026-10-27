<?php

namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\DivisionRequest;
use App\Http\Resources\Organization\DivisionResource;
use App\Models\Division;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Master Bidang.
 *
 *   GET    /divisions          daftar
 *   POST   /divisions          tambah
 *   GET    /divisions/{id}     detail
 *   PUT    /divisions/{id}     ubah
 *   DELETE /divisions/{id}     hapus (ditolak kalau masih dipakai jabatan)
 */
class DivisionController extends Controller
{
    /**
     * Daftar bidang
     */
    public function index(): JsonResponse
    {
        $divisions = Division::query()->withCount('positions')->orderBy('id')->get();

        return ApiResponse::success(DivisionResource::collection($divisions), 'Daftar bidang.');
    }

    /**
     * Tambah bidang
     */
    public function store(DivisionRequest $request): JsonResponse
    {
        $division = Division::create($request->validated());

        return ApiResponse::success(DivisionResource::make($division->loadCount('positions')), 'Bidang berhasil ditambahkan.', 201);
    }

    /**
     * Detail bidang
     */
    public function show(Division $division): JsonResponse
    {
        return ApiResponse::success(DivisionResource::make($division->loadCount('positions')), 'Detail bidang.');
    }

    /**
     * Ubah bidang
     */
    public function update(DivisionRequest $request, Division $division): JsonResponse
    {
        $division->update($request->validated());

        return ApiResponse::success(DivisionResource::make($division->loadCount('positions')), 'Bidang berhasil diperbarui.');
    }

    /**
     * Hapus bidang
     *
     * Ditolak (409) kalau bidang masih dipakai jabatan.
     */
    public function destroy(Division $division): JsonResponse
    {
        $positionCount = $division->positions()->count();

        if ($positionCount > 0) {
            abort(409, "Bidang \"{$division->name}\" masih dipakai oleh {$positionCount} jabatan. Pindahkan jabatannya ke bidang lain terlebih dahulu.");
        }

        $division->delete();

        return ApiResponse::success(null, 'Bidang berhasil dihapus.');
    }
}
