<?php

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendanceCategoryRequest;
use App\Services\Attendance\AttendanceOverview;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Tab Daftar Hadir: ringkasan absensi per karyawan kategori itu (Casual / Daily Worker) yang aktif dalam periode.
 *
 *   GET /attendance-summaries?period=YYYY-MM&employee_category=casual|daily_worker
 *   (Daily Worker: regular_overtime terisi, dw_overtime selalu 0)
 */
class AttendanceSummaryController extends Controller
{
    /**
     * Ringkasan daftar hadir
     *
     * Per karyawan: jumlah kode, hari aktif tanpa kode, total lembur reguler & DW, jumlah konflik, peringatan.
     */
    public function __invoke(AttendanceCategoryRequest $request, AttendanceOverview $overview): JsonResponse
    {
        return ApiResponse::success($overview->summaries($request->period(), $request->employeeCategory()), 'Ringkasan daftar hadir.');
    }
}
