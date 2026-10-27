<?php

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\EmployeeExportRequest;
use App\Services\Employee\EmployeeExportService;
use App\Services\Employee\EmployeeExportWorkbook;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Ekspor Data Karyawan ke Excel.
 *
 *   GET /employees/export?period=2026-09&active_status=Aktif   -> file .xlsx (bukan JSON)
 *
 * `period` (opsional) = data per periode itu & nama file memakai bulan periode; tanpa `period` = per hari ini.
 *
 * Alurnya: EmployeeExportService menyusun baris data -> EmployeeExportWorkbook menulis file Excel
 * (format kolom ada di EmployeeExportColumns) -> file dikirim lalu dihapus.
 */
class EmployeeExportController extends Controller
{
    /**
     * Ekspor Data Karyawan (Excel)
     *
     * Mengirim file .xlsx, bukan JSON. Nama file ada di header Content-Disposition.
     */
    public function __invoke(
        EmployeeExportRequest $request,
        EmployeeExportService $exportService,
        EmployeeExportWorkbook $workbook,
    ): BinaryFileResponse {
        $period = $request->validated('period');

        $rows = $exportService->rows($request->validated('active_status'), $period);
        $path = $workbook->write($rows);
        $fileName = $exportService->fileName($period);

        return response()
            ->file($path, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$fileName.'"; filename*=UTF-8\'\''.rawurlencode($fileName),
            ])
            ->deleteFileAfterSend();
    }
}
