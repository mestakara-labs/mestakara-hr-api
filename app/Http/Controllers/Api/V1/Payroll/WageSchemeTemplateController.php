<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\WageSchemeIndexRequest;
use App\Services\Payroll\WageSchemeTemplateService;
use App\Services\Payroll\WageSchemeTemplateWorkbook;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Unduh template impor Skema Upah.
 *
 *   GET /wage-schemes/template?period=2026-10   -> file .xlsx (bukan JSON)
 *
 * Berisi SEMUA karyawan aktif pada periode itu + nilai yang berlaku. Skema upah hanya untuk Casual & DW;
 * rekening & cost center untuk semua karyawan. Alurnya: WageSchemeTemplateService menyusun baris ->
 * WageSchemeTemplateWorkbook menulis file (kolom di WageSchemeTemplateColumns) -> file dikirim lalu dihapus.
 */
class WageSchemeTemplateController extends Controller
{
    /**
     * Unduh template skema upah (Excel)
     *
     * Mengirim file .xlsx, bukan JSON. Nama file ada di header Content-Disposition.
     */
    public function __invoke(
        WageSchemeIndexRequest $request,
        WageSchemeTemplateService $templateService,
        WageSchemeTemplateWorkbook $workbook,
    ): BinaryFileResponse {
        $period = $request->validated('period');

        $path = $workbook->write($templateService->rows($period), $templateService->lists());
        $fileName = $templateService->fileName($period);

        return response()
            ->file($path, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$fileName.'"; filename*=UTF-8\'\''.rawurlencode($fileName),
            ])
            ->deleteFileAfterSend();
    }
}
