<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\PayrollExportRequest;
use App\Http\Requests\Payroll\SpklExportRequest;
use App\Services\Payroll\PayrollData;
use App\Services\Payroll\PayrollExportDocument;
use App\Services\Payroll\PayrollExportWorkbook;
use App\Services\Payroll\PdfRenderer;
use App\Services\Payroll\SpklDocument;
use App\Services\Payroll\SpklWorkbook;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * EKSPOR Daftar Upah & SPKL (Excel / PDF). Mengirim file, bukan JSON; nama file di header Content-Disposition.
 *
 *   GET /payroll/export?period&group_by&group_key&format=xlsx|pdf
 *   GET /payroll/spkl?period&type=reguler|dw&group_by&group_key&format&employee_ids=a,b
 *
 * Alurnya: PayrollData (hitung / potret) -> *Document menyusun isi -> *Workbook (Excel) atau view Blade + dompdf (PDF).
 * Periode draf diberi tanda "(DRAF)" di nama file & dokumen (PDF juga watermark).
 */
class PayrollExportController extends Controller
{
    private const CONTENT_TYPES = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pdf' => 'application/pdf',
    ];

    /**
     * Ekspor Daftar Upah
     */
    public function payroll(PayrollExportRequest $request, PayrollExportDocument $builder, PayrollExportWorkbook $workbook, PdfRenderer $pdf): BinaryFileResponse
    {
        $format = $request->fileFormat();
        $document = $builder->build(PayrollData::load($request->period()), $request->groupBy(), $request->groupKey());

        $path = $format === 'pdf'
            ? $pdf->write('payroll.daftar-upah', ['document' => $document], 'landscape')
            : $workbook->write($document);

        return $this->download($path, ($document['file_name'])($format), $format);
    }

    /**
     * Ekspor SPKL (Daftar Lembur)
     */
    public function spkl(SpklExportRequest $request, SpklDocument $builder, SpklWorkbook $workbook, PdfRenderer $pdf): BinaryFileResponse
    {
        $format = $request->fileFormat();
        $document = $builder->build(
            PayrollData::load($request->period()),
            $request->overtimeType(),
            $request->groupBy(),
            $request->groupKey(),
            $request->employeeIds(),
        );

        $path = $format === 'pdf'
            ? $pdf->write('payroll.spkl', ['document' => $document], 'portrait')
            : $workbook->write($document);

        return $this->download($path, ($document['file_name'])($format), $format);
    }

    private function download(string $path, string $fileName, string $format): BinaryFileResponse
    {
        return response()
            ->file($path, [
                'Content-Type' => self::CONTENT_TYPES[$format],
                'Content-Disposition' => 'attachment; filename="'.$fileName.'"; filename*=UTF-8\'\''.rawurlencode($fileName),
            ])
            ->deleteFileAfterSend();
    }
}
