<?php

namespace App\Http\Controllers\Api\V1\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\ShiftRequest;
use App\Http\Resources\Schedule\ShiftResource;
use App\Models\Schedule;
use App\Models\Shift;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Master Shift (jam kerja).
 *
 *   GET    /shifts          daftar
 *   POST   /shifts          tambah
 *   GET    /shifts/{id}     detail
 *   PUT    /shifts/{id}     ubah
 *   DELETE /shifts/{id}     hapus (ditolak kalau masih dipakai pola kerja)
 */
class ShiftController extends Controller
{
    /**
     * Daftar shift
     */
    public function index(): JsonResponse
    {
        $shifts = Shift::query()->orderBy('start_time')->orderBy('name')->get();

        return ApiResponse::success(ShiftResource::collection($shifts), 'Daftar shift.');
    }

    /**
     * Tambah shift
     */
    public function store(ShiftRequest $request): JsonResponse
    {
        $shift = Shift::create($request->validated());

        return ApiResponse::success(ShiftResource::make($shift), 'Shift berhasil ditambahkan.', 201);
    }

    /**
     * Detail shift
     */
    public function show(Shift $shift): JsonResponse
    {
        return ApiResponse::success(ShiftResource::make($shift), 'Detail shift.');
    }

    /**
     * Ubah shift
     *
     * Jadwal yang sudah dibuat tidak ikut berubah (jamnya sudah disalin).
     */
    public function update(ShiftRequest $request, Shift $shift): JsonResponse
    {
        $shift->update($request->validated());

        return ApiResponse::success(ShiftResource::make($shift), 'Shift berhasil diperbarui.');
    }

    /**
     * Hapus shift
     *
     * Ditolak (409) kalau masih dipakai pola kerja atau sudah ada di jadwal.
     */
    public function destroy(Shift $shift): JsonResponse
    {
        $patternCount = $shift->patternCount();

        if ($patternCount > 0) {
            abort(409, "Shift \"{$shift->name}\" masih dipakai di {$patternCount} pola kerja. Ganti shift di pola tersebut terlebih dahulu.");
        }

        $isScheduled = Schedule::where('shift_id', $shift->id)->orWhere('generated_shift_id', $shift->id)->exists();
        abort_if($isScheduled, 409, "Shift \"{$shift->name}\" sudah dipakai di jadwal kerja, jadi tidak bisa dihapus.");

        $shift->delete();

        return ApiResponse::success(null, 'Shift berhasil dihapus.');
    }
}
