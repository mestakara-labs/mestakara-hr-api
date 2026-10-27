<?php

namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\PositionRequest;
use App\Http\Resources\Organization\PositionResource;
use App\Models\Position;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Master Jabatan.
 *
 *   GET    /positions          daftar
 *   POST   /positions          tambah
 *   GET    /positions/{id}     detail
 *   PUT    /positions/{id}     ubah
 *   DELETE /positions/{id}     hapus
 */
class PositionController extends Controller
{
    /**
     * Daftar jabatan
     */
    public function index(): JsonResponse
    {
        $positions = Position::query()->with(PositionResource::RELATIONS)->orderBy('id')->get();

        return ApiResponse::success(PositionResource::collection($positions), 'Daftar jabatan.');
    }

    /**
     * Tambah jabatan
     *
     * Jabatan baru belum punya pemegang; pemegangnya diatur lewat Mutasi & Penempatan.
     */
    public function store(PositionRequest $request): JsonResponse
    {
        $position = Position::create($request->validated());

        return ApiResponse::success(PositionResource::make($position->load(PositionResource::RELATIONS)), 'Jabatan berhasil ditambahkan.', 201);
    }

    /**
     * Detail jabatan
     */
    public function show(Position $position): JsonResponse
    {
        return ApiResponse::success(PositionResource::make($position->load(PositionResource::RELATIONS)), 'Detail jabatan.');
    }

    /**
     * Ubah jabatan
     */
    public function update(PositionRequest $request, Position $position): JsonResponse
    {
        $position->update($request->validated());

        return ApiResponse::success(PositionResource::make($position->load(PositionResource::RELATIONS)), 'Jabatan berhasil diperbarui.');
    }

    /**
     * Hapus jabatan
     *
     * Ditolak (409) kalau jabatan pernah dipakai penugasan (aktif maupun riwayat).
     */
    public function destroy(Position $position): JsonResponse
    {
        $assignmentCount = $position->assignments()->count();

        if ($assignmentCount > 0) {
            abort(409, "\"{$position->title}\" masih dipakai di {$assignmentCount} penugasan jabatan (termasuk riwayat). Hapus penugasannya terlebih dahulu.");
        }

        $position->delete();

        return ApiResponse::success(null, 'Jabatan berhasil dihapus.');
    }
}
