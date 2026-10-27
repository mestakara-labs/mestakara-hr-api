<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\OvertimeSettingRequest;
use App\Http\Resources\Payroll\OvertimeSettingResource;
use App\Models\OvertimeSetting;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Pengaturan Lembur (satu set pengaturan untuk seluruh unit).
 * Penanda tangan SPKL & Daftar Upah diatur di /signature-settings.
 *
 *   GET /overtime-settings   pengaturan
 *   PUT /overtime-settings   ubah
 */
class OvertimeSettingController extends Controller
{
    /**
     * Pengaturan lembur
     */
    public function show(): JsonResponse
    {
        return ApiResponse::success(OvertimeSettingResource::make(OvertimeSetting::current()), 'Pengaturan lembur.');
    }

    /**
     * Ubah pengaturan lembur
     */
    public function update(OvertimeSettingRequest $request): JsonResponse
    {
        $setting = OvertimeSetting::current();
        $setting->update([...$request->validated(), 'updated_by' => auth()->id()]);

        return ApiResponse::success(OvertimeSettingResource::make($setting->fresh('updater')), 'Pengaturan lembur berhasil disimpan.');
    }
}
