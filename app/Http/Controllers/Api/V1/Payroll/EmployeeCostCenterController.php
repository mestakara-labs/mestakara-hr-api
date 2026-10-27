<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\EmployeeCostCenterRequest;
use App\Http\Resources\Payroll\EmployeeCostCenterResource;
use App\Models\Employee;
use App\Support\ApiResponse;
use App\Support\EffectiveRecords;
use Illuminate\Http\JsonResponse;

/**
 * Riwayat Cost Center karyawan (semua status karyawan). Catatan tidak bisa diubah/dihapus.
 *
 *   GET  /employees/{id}/cost-centers   riwayat (yang berlaku paling akhir di atas)
 *   POST /employees/{id}/cost-centers   tambah catatan
 */
class EmployeeCostCenterController extends Controller
{
    /**
     * Riwayat cost center
     */
    public function index(Employee $employee): JsonResponse
    {
        $histories = EffectiveRecords::newestFirst($employee->costCenterHistories())->with(['costCenter', 'creator'])->get();

        return ApiResponse::success(EmployeeCostCenterResource::collection($histories), 'Riwayat cost center.');
    }

    /**
     * Tambah catatan cost center
     */
    public function store(EmployeeCostCenterRequest $request, Employee $employee): JsonResponse
    {
        $history = $employee->costCenterHistories()->create([...$request->validated(), 'created_by' => auth()->id()]);

        return ApiResponse::success(EmployeeCostCenterResource::make($history->load(['costCenter', 'creator'])), 'Cost center berhasil dicatat.', 201);
    }
}
