<?php

namespace App\Http\Controllers\Api\V1\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\HolidayRequest;
use App\Models\Holiday;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Master Hari Libur (penanda di kalender jadwal).
 *
 *   GET    /holidays          daftar (urut tanggal)
 *   POST   /holidays          tambah
 *   GET    /holidays/{id}     detail
 *   PUT    /holidays/{id}     ubah
 *   DELETE /holidays/{id}     hapus
 */
class HolidayController extends Controller
{
    /**
     * Daftar hari libur
     */
    public function index(): JsonResponse
    {
        $holidays = Holiday::query()->orderBy('date')->get()->map(fn (Holiday $holiday) => $this->toArray($holiday));

        return ApiResponse::success($holidays, 'Daftar hari libur.');
    }

    /**
     * Tambah hari libur
     */
    public function store(HolidayRequest $request): JsonResponse
    {
        $holiday = Holiday::create($request->validated());

        return ApiResponse::success($this->toArray($holiday), 'Hari libur berhasil ditambahkan.', 201);
    }

    /**
     * Detail hari libur
     */
    public function show(Holiday $holiday): JsonResponse
    {
        return ApiResponse::success($this->toArray($holiday), 'Detail hari libur.');
    }

    /**
     * Ubah hari libur
     */
    public function update(HolidayRequest $request, Holiday $holiday): JsonResponse
    {
        $holiday->update($request->validated());

        return ApiResponse::success($this->toArray($holiday), 'Hari libur berhasil diperbarui.');
    }

    /**
     * Hapus hari libur
     */
    public function destroy(Holiday $holiday): JsonResponse
    {
        $holiday->delete();

        return ApiResponse::success(null, 'Hari libur berhasil dihapus.');
    }

    /** Hari libur sesuai HolidayResource di front end (types/api/holiday.ts). */
    private function toArray(Holiday $holiday): array
    {
        return [
            'id' => $holiday->id,
            'date' => $holiday->date->toDateString(),
            'name' => $holiday->name,
            'type' => $holiday->type,
            'description' => $holiday->description,
        ];
    }
}
