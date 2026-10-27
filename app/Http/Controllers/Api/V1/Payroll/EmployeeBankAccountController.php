<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\EmployeeBankAccountRequest;
use App\Http\Resources\Payroll\EmployeeBankAccountResource;
use App\Models\Employee;
use App\Support\ApiResponse;
use App\Support\EffectiveRecords;
use Illuminate\Http\JsonResponse;

/**
 * Riwayat Rekening Bank karyawan (semua status karyawan). Catatan tidak bisa diubah/dihapus.
 *
 *   GET  /employees/{id}/bank-accounts   riwayat (yang berlaku paling akhir di atas)
 *   POST /employees/{id}/bank-accounts   tambah catatan
 */
class EmployeeBankAccountController extends Controller
{
    /**
     * Riwayat rekening bank
     */
    public function index(Employee $employee): JsonResponse
    {
        $accounts = EffectiveRecords::newestFirst($employee->bankAccounts())->with('creator')->get();

        return ApiResponse::success(EmployeeBankAccountResource::collection($accounts), 'Riwayat rekening bank.');
    }

    /**
     * Tambah catatan rekening bank
     */
    public function store(EmployeeBankAccountRequest $request, Employee $employee): JsonResponse
    {
        $account = $employee->bankAccounts()->create([...$request->validated(), 'created_by' => auth()->id()]);

        return ApiResponse::success(EmployeeBankAccountResource::make($account->load('creator')), 'Rekening bank berhasil dicatat.', 201);
    }
}
