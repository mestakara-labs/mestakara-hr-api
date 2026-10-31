<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\AnalyticsRequest;
use App\Services\Dashboard\AnalyticsService;
use App\Services\Dashboard\AnalyticsWorkbook;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Analitik SDM (Tahap A: topik workforce & payroll).
 *
 *   GET /analytics?topic=payroll&metric=net&group_by=division&split_by=employment_status&from=&to=&filters[..][]=
 *   GET /analytics/export?(isian sama)   -> file .xlsx "Analitik SDM - <Topik> per <Dimensi> - <Jan – Okt 2026>.xlsx"
 */
class AnalyticsController extends Controller
{
    public function __construct(private AnalyticsService $analytics) {}

    /**
     * Analitik SDM
     */
    public function index(AnalyticsRequest $request): JsonResponse
    {
        return ApiResponse::success($this->analytics->run($request->analyticsQuery()), 'Analitik SDM.');
    }

    /**
     * Ekspor analitik SDM (Excel)
     *
     * Mengirim file .xlsx, bukan JSON. Nama file ada di header Content-Disposition.
     */
    public function export(AnalyticsRequest $request, AnalyticsWorkbook $workbook): BinaryFileResponse
    {
        $query = $request->analyticsQuery();
        $path = $workbook->write($query, $this->analytics->run($query));
        $fileName = $query->fileName();

        return response()
            ->file($path, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.Str::ascii($fileName).'"; filename*=UTF-8\'\''.rawurlencode($fileName),
            ])
            ->deleteFileAfterSend();
    }
}
