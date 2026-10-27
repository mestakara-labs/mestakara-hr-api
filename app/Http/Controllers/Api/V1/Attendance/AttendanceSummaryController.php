<?php

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendancePeriodRequest;
use App\Services\Attendance\AttendanceOverview;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Tab Daftar Hadir: ringkasan absensi per karyawan Casual & DW yang aktif dalam periode.
 *
 *   GET /attendance-summaries?period=YYYY-MM
 */
class AttendanceSummaryController extends Controller
{
    /**
     * Ringkasan daftar hadir
     *
     * Per karyawan: jumlah kode, hari aktif tanpa kode, total lembur reguler & DW, jumlah konflik, peringatan.
     */
    public function __invoke(AttendancePeriodRequest $request, AttendanceOverview $overview): JsonResponse
    {
        return ApiResponse::success($overview->summaries($request->period()), 'Ringkasan daftar hadir.');
    }
}
