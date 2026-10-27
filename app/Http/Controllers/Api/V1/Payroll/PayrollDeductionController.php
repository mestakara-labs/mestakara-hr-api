<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\PayrollDeductionCopyRequest;
use App\Http\Requests\Payroll\PayrollDeductionRequest;
use App\Services\Payroll\PayrollDeductionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * POTONGAN daftar upah (Tahap 4).
 *
 *   PUT  /payroll/deductions                 ubah potongan satu / banyak karyawan
 *   POST /payroll/deductions/copy-previous   salin potongan periode sebelumnya (save=false = pratinjau)
 */
class PayrollDeductionController extends Controller
{
    /**
     * Ubah potongan
     *
     * Hanya isian yang dikirim yang berubah. 422 kalau periode dikunci atau potongan melebihi jumlah upah
     * (pesan per karyawan di `errors["employee_ids.<i>"]`).
     */
    public function update(PayrollDeductionRequest $request, PayrollDeductionService $service): JsonResponse
    {
        $count = $service->update($request->period(), $request->validated('employee_ids'), $request->deductionValues());

        return ApiResponse::success(['employees' => $count], "Potongan {$count} karyawan berhasil disimpan.");
    }

    /**
     * Salin potongan periode sebelumnya
     *
     * Hanya untuk karyawan yang potongannya masih 0 semua dan potongan periode sebelumnya > 0.
     */
    public function copyPrevious(PayrollDeductionCopyRequest $request, PayrollDeductionService $service): JsonResponse
    {
        $save = $request->boolean('save');
        $result = $service->copyPrevious($request->period(), $save);
        $count = count($result['employees']);

        return ApiResponse::success(
            $result,
            $save ? "Potongan {$count} karyawan berhasil disalin." : "Pratinjau salin potongan: {$count} karyawan.",
        );
    }
}
