<?php

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendancePeriodRequest;
use App\Http\Requests\Attendance\DwRecommendationPreviewRequest;
use App\Http\Requests\Attendance\DwRecommendationStoreRequest;
use App\Services\Attendance\DwRecommendationService;
use App\Services\Attendance\DwTargets;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * LEMBUR DW OTOMATIS (Tahap 3).
 *
 *   GET  /overtime-dw/targets?period=YYYY-MM          target vs upah Lembur DW per karyawan
 *   POST /overtime-dw/recommendations/preview         hitung rekomendasi (acak), TIDAK menyimpan
 *   POST /overtime-dw/recommendations                 simpan rekomendasi (validasi ulang; 422 kalau salah)
 *
 * Aturan hitung di App\Services\Attendance\DwPay; penyebaran di DwRecommender.
 */
class OvertimeDwController extends Controller
{
    /**
     * Target Lembur DW per karyawan
     *
     * Karyawan Casual & DW yang punya hari DW atau lembur DW di periode itu.
     */
    public function targets(AttendancePeriodRequest $request, DwTargets $targets): JsonResponse
    {
        return ApiResponse::success($targets->forPeriod($request->period()), 'Target Lembur DW.');
    }

    /**
     * Pratinjau rekomendasi Lembur DW
     *
     * Hasilnya acak setiap kali dipanggil. Lembur DW manual/mobile dipertahankan dan upahnya jadi titik awal.
     */
    public function preview(DwRecommendationPreviewRequest $request, DwRecommendationService $service): JsonResponse
    {
        $result = $service->preview($request->period(), $request->validated('employee_ids'));

        return ApiResponse::success($result, 'Pratinjau rekomendasi Lembur DW.');
    }

    /**
     * Simpan rekomendasi Lembur DW
     *
     * Per karyawan: Lembur DW Excel & rekomendasi lama di periode itu dihapus, lalu hasil baru disimpan
     * (sumber rekomendasi_sistem). Error per karyawan / baris di `errors["employees.<i>"]` / `errors["employees.<i>.entries.<j>"]`.
     */
    public function store(DwRecommendationStoreRequest $request, DwRecommendationService $service): JsonResponse
    {
        $result = $service->store($request->period(), $request->validated('employees'));

        return ApiResponse::success($result, "Rekomendasi Lembur DW {$result['saved_employees']} karyawan berhasil disimpan.", 201);
    }
}
