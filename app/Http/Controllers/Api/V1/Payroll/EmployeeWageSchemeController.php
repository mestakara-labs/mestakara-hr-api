<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\EmployeeWageSchemeRequest;
use App\Http\Resources\Payroll\WageSchemeResource;
use App\Models\Employee;
use App\Services\Payroll\WageSchemeService;
use App\Support\ApiResponse;
use App\Support\EffectiveRecords;
use Illuminate\Http\JsonResponse;

/**
 * Riwayat Skema Upah satu karyawan (HANYA Casual & Daily Worker). Catatan tidak bisa diubah/dihapus.
 *
 *   GET  /employees/{id}/wage-schemes   riwayat (yang berlaku paling akhir di atas)
 *   POST /employees/{id}/wage-schemes   tambah catatan (422 kalau bukan Casual / Daily Worker)
 */
class EmployeeWageSchemeController extends Controller
{
    /**
     * Riwayat skema upah
     */
    public function index(Employee $employee): JsonResponse
    {
        $schemes = EffectiveRecords::newestFirst($employee->wageSchemes())->with(['payrollGroup', 'creator'])->get();

        return ApiResponse::success(WageSchemeResource::collection($schemes), 'Riwayat skema upah.');
    }

    /**
     * Tambah catatan skema upah
     *
     * `has_meal_allowance: true` wajib bersama `meal_allowance_per_day` > 0; kalau false, uang makan disimpan 0.
     * `payroll_group_id` boleh kosong.
     */
    public function store(EmployeeWageSchemeRequest $request, Employee $employee, WageSchemeService $service): JsonResponse
    {
        $scheme = $service->create($employee, $request->validated());

        return ApiResponse::success(WageSchemeResource::make($scheme->load(['payrollGroup', 'creator'])), 'Skema upah berhasil dicatat.', 201);
    }
}
