<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\WageSchemeBulkRequest;
use App\Http\Requests\Payroll\WageSchemeIndexRequest;
use App\Http\Resources\Payroll\WageSchemeEmployeeResource;
use App\Models\Employee;
use App\Services\Payroll\ActivePayrollEmployees;
use App\Services\Payroll\WageSchemeService;
use App\Support\ApiResponse;
use App\Support\DataPeriod;
use Illuminate\Http\JsonResponse;

/**
 * Halaman Skema Upah (Karyawan Casual & Daily Worker).
 *
 *   GET  /wage-schemes?period=YYYY-MM   karyawan Casual & DW AKTIF + skema, rekening, cost center yang berlaku
 *   POST /wage-schemes/bulk             ubah skema banyak karyawan sekaligus (catatan baru per karyawan)
 */
class WageSchemeController extends Controller
{
    /**
     * Daftar skema upah per periode
     *
     * Aturan "aktif" sama dengan GET /employees?period=. `wage_scheme: null` = "Belum diatur".
     */
    public function index(WageSchemeIndexRequest $request, ActivePayrollEmployees $activeEmployees): JsonResponse
    {
        $asOf = DataPeriod::asOf($request->validated('period'));

        $rows = $activeEmployees->get($asOf, dailyWageOnly: true)
            ->map(fn (Employee $employee) => WageSchemeEmployeeResource::make($employee)->asOf($asOf));

        return ApiResponse::success($rows, 'Daftar skema upah.');
    }

    /**
     * Ubah skema upah massal
     *
     * Untuk tiap karyawan: skema yang berlaku pada `effective_date` disalin, isian di `fields` ditimpakan,
     * lalu disimpan sebagai catatan baru. Kalau ada karyawan yang salah, tidak ada yang disimpan (422,
     * error per karyawan di `errors["employees.<id>"]`).
     */
    public function bulk(WageSchemeBulkRequest $request, WageSchemeService $service): JsonResponse
    {
        $count = $service->bulk(
            $request->validated('employee_ids'),
            $request->validated('effective_date'),
            $request->validated('fields'),
            $request->validated('notes'),
        );

        return ApiResponse::success(['employee_count' => $count], "Skema upah {$count} karyawan berhasil diperbarui.");
    }
}
