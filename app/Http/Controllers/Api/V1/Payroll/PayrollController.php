<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\PayrollLockRequest;
use App\Http\Requests\Payroll\PayrollRequest;
use App\Services\Payroll\PayrollData;
use App\Services\Payroll\PayrollLockService;
use App\Services\Payroll\PayrollReport;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * DAFTAR UPAH Karyawan Casual & Daily Worker (Tahap 4).
 *
 *   GET  /payroll?period=YYYY-MM&group_by=payroll_group|division   status, kesiapan, ringkasan, kelompok, baris
 *   POST /payroll/lock     { period }           kunci + simpan potret (hanya bulan yang sudah berakhir)
 *   POST /payroll/unlock   { period, reason }   buka kunci (potret dihapus)
 *
 * Rumus di App\Services\Payroll\PayrollCalculator; periode dikunci memakai potret.
 */
class PayrollController extends Controller
{
    /**
     * Daftar upah satu periode
     */
    public function index(PayrollRequest $request, PayrollReport $report): JsonResponse
    {
        $data = PayrollData::load($request->period());

        return ApiResponse::success($report->build($data, $request->groupBy()), 'Daftar upah.');
    }

    /**
     * Kunci periode upah
     *
     * Bulan berjalan / bulan depan -> 422 "Periode belum berakhir".
     */
    public function lock(PayrollLockRequest $request, PayrollLockService $service, PayrollReport $report): JsonResponse
    {
        $period = $request->period();
        $service->lock($period);

        return ApiResponse::success($report->status($period), "Periode upah {$period->label()} berhasil dikunci.");
    }

    /**
     * Buka kunci periode upah
     */
    public function unlock(PayrollLockRequest $request, PayrollLockService $service, PayrollReport $report): JsonResponse
    {
        $period = $request->period();
        $service->unlock($period, $request->validated('reason'));

        return ApiResponse::success($report->status($period), "Kunci periode upah {$period->label()} berhasil dibuka.");
    }
}
