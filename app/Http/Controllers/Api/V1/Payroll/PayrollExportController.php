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
 *
 * Ekspor berat (data 364 karyawan, diukur Oktober 2026): PDF Daftar Upah ± 300 MB kalau dirender sekaligus, Excel SPKL
 * semua karyawan ± 210 MB & 18 detik, PDF SPKL ± 6 MB & 0,3 detik PER HALAMAN. Karena itu:
 *   - batas memori & waktu PHP dinaikkan khusus untuk ekspor (raiseLimits);
 *   - PDF dirender PER BAGIAN lalu digabung jadi satu file (PdfRenderer::writeMerged): Daftar Upah per sheet,
 *     SPKL per SPKL_PDF_PAGES_PER_PART karyawan.
 */
class PayrollExportController extends Controller
{
    private const CONTENT_TYPES = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pdf' => 'application/pdf',
    ];

    /** Batas memori & waktu PHP selama membuat file ekspor (bawaan server 128 MB & 30 detik). */
    private const MEMORY_LIMIT = '1024M';

    private const TIME_LIMIT_SECONDS = 300;

    /** Jumlah karyawan (= halaman) per bagian render PDF SPKL; ± 6 MB memori per halaman. */
    public const SPKL_PDF_PAGES_PER_PART = 25;

    /**
     * Ekspor Daftar Upah
     */
    public function payroll(PayrollExportRequest $request, PayrollExportDocument $builder, PayrollExportWorkbook $workbook, PdfRenderer $pdf): BinaryFileResponse
    {
        $this->raiseLimits();
        $format = $request->fileFormat();
        $document = $builder->build(PayrollData::load($request->period()), $request->groupBy(), $request->groupKey());

        $path = $format === 'pdf'
            // F4 mendatar; tata letak diperkecil di view supaya 21 kolom muat (lihat payroll/daftar-upah.blade.php).
            ? $pdf->writeMerged('payroll.daftar-upah', $this->payrollPdfParts($document), 'landscape', 'folio')
            : $workbook->write($document);

        return $this->download($path, ($document['file_name'])($format), $format);
    }

    /**
     * Ekspor SPKL (Daftar Lembur)
     */
    public function spkl(SpklExportRequest $request, SpklDocument $builder, SpklWorkbook $workbook, PdfRenderer $pdf): BinaryFileResponse
    {
        $this->raiseLimits();
        $format = $request->fileFormat();
        $document = $builder->build(
            PayrollData::load($request->period()),
            $request->overtimeType(),
            $request->groupBy(),
            $request->groupKey(),
            $request->employeeIds(),
        );

        $path = $format === 'pdf'
            ? $pdf->writeMerged('payroll.spkl', $this->spklPdfParts($document), 'portrait')
            : $workbook->write($document);

        return $this->download($path, ($document['file_name'])($format), $format);
    }

    /**
     * Bagian render PDF Daftar Upah: satu sheet (kelompok / REKAP / PAYROLL) per bagian.
     *
     * @return list<array{document: array<string, mixed>}>
     */
    private function payrollPdfParts(array $document): array
    {
        return array_map(fn (array $sheet) => ['document' => [...$document, 'sheets' => [$sheet]]], $document['sheets']);
    }

    /**
     * Bagian render PDF SPKL: SPKL_PDF_PAGES_PER_PART karyawan per bagian, urutan kelompok & karyawan tetap.
     *
     * @return list<array{document: array<string, mixed>}>
     */
    private function spklPdfParts(array $document): array
    {
        $blocks = [];   // [kelompok, blok] berurutan
        foreach ($document['groups'] as $group) {
            foreach ($group['blocks'] as $block) {
                $blocks[] = [$group, $block];
            }
        }

        $parts = [];
        foreach (array_chunk($blocks, self::SPKL_PDF_PAGES_PER_PART) as $chunk) {
            $groups = [];
            foreach ($chunk as [$group, $block]) {
                $groups[$group['title']] ??= [...$group, 'blocks' => []];
                $groups[$group['title']]['blocks'][] = $block;
            }
            $parts[] = ['document' => [...$document, 'groups' => array_values($groups)]];
        }

        return $parts;
    }

    private function raiseLimits(): void
    {
        ini_set('memory_limit', self::MEMORY_LIMIT);
        set_time_limit(self::TIME_LIMIT_SECONDS);
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
