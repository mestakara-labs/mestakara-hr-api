<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\PayrollExportRequest;
use App\Services\Payroll\PayrollData;
use App\Services\Payroll\PayrollExportDocument;
use App\Services\Payroll\PayrollExportWorkbook;
use App\Services\Payroll\PdfRenderer;
use App\Services\Payroll\SpklDocument;
use App\Services\Payroll\SpklWorkbook;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * EKSPOR Daftar Upah, Payroll & SPKL (Excel / PDF) — SATU endpoint. Mengirim file, bukan JSON; nama file di header
 * Content-Disposition.
 *
 *   GET /payroll/export?period&group_by&employee_category&document&group_keys=a,b&format=xlsx|pdf&employee_ids=a,b
 *       &sign_date=YYYY-MM-DD&show_draft=1|0
 *
 * Isi query lihat PayrollExportRequest. Setiap dokumen: REKAP dulu, lalu satu sheet / bagian halaman per kelompok.
 * Alurnya: PayrollData (hitung / potret) -> PayrollExportDocument / SpklDocument menyusun isi -> *Workbook (Excel)
 * atau view Blade + dompdf (PDF). Periode draf diberi tanda "(DRAF)" di nama file & dokumen (PDF juga watermark),
 * kecuali show_draft=0; tanggal tanda tangan bisa dipilih lewat sign_date (PayrollExportOptions).
 *
 * Ekspor berat (data 364 karyawan, diukur Oktober 2026): PDF Daftar Upah ± 300 MB kalau dirender sekaligus, Excel SPKL
 * semua karyawan ± 210 MB & 18 detik, PDF SPKL ± 6 MB & 0,3 detik PER HALAMAN. Karena itu:
 *   - batas memori & waktu PHP dinaikkan khusus untuk ekspor (raiseLimits);
 *   - PDF dirender PER BAGIAN lalu digabung jadi satu file (PdfRenderer): Daftar Upah / Payroll per sheet,
 *     SPKL = REKAP lalu per SPKL_PDF_PAGES_PER_PART karyawan. Kalau bagian dirender bersamaan (config hr.pdf_processes),
 *     karyawan SPKL dibagi rata ke jumlah proses supaya semua proses selesai hampir bersamaan.
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

    /** REKAP & Daftar Upah / Payroll: F4 mendatar (tata letak diperkecil di view payroll/daftar-upah.blade.php). */
    private const TABLE_PDF = ['view' => 'payroll.daftar-upah', 'orientation' => 'landscape', 'paper' => 'folio'];

    /**
     * Ekspor Daftar Upah / Payroll / SPKL
     */
    public function __invoke(
        PayrollExportRequest $request,
        PayrollExportDocument $payrollBuilder,
        SpklDocument $spklBuilder,
        PayrollExportWorkbook $payrollWorkbook,
        SpklWorkbook $spklWorkbook,
        PdfRenderer $pdf,
    ): BinaryFileResponse {
        $this->raiseLimits();
        $format = $request->fileFormat();
        $documentType = $request->document();
        $data = PayrollData::load($request->period());

        $options = $request->exportOptions();

        if ($documentType->isSpkl()) {
            $document = $spklBuilder->build($data, $documentType, $request->groupBy(), $request->groupKeys(), $request->employeeIds(), $request->employeeCategory(), $options);
            $path = $format === 'pdf' ? $pdf->writeParts($this->spklPdfParts($document)) : $spklWorkbook->write($document);
        } else {
            $document = $payrollBuilder->build($data, $documentType, $request->groupBy(), $request->groupKeys(), $request->employeeCategory(), $options);
            $path = $format === 'pdf'
                ? $pdf->writeParts(array_map(fn (array $sheet) => [...self::TABLE_PDF, 'data' => ['document' => [...$document, 'sheets' => [$sheet]]]], $document['sheets']))
                : $payrollWorkbook->write($document);
        }

        return $this->download($path, ($document['file_name'])($format), $format);
    }

    /**
     * Bagian render PDF SPKL: REKAP (F4 mendatar, satu baris per kelompok), lalu SPKL_PDF_PAGES_PER_PART karyawan per
     * bagian (A4 tegak), urutan kelompok & karyawan tetap.
     *
     * @return list<array{view: string, data: array<string, mixed>, orientation: string, paper: string}>
     */
    private function spklPdfParts(array $document): array
    {
        $parts = [[...self::TABLE_PDF, 'data' => ['document' => [...$document, 'sheets' => [$document['rekap']]]]]];

        $blocks = [];   // [kelompok, blok] berurutan
        foreach ($document['groups'] as $group) {
            foreach ($group['blocks'] as $block) {
                $blocks[] = [$group, $block];
            }
        }

        // Berurutan: SPKL_PDF_PAGES_PER_PART karyawan per bagian (memori). Bersamaan: dibagi rata ke jumlah proses.
        $processes = PdfRenderer::processCount(count($blocks));
        $perPart = $processes > 1 ? (int) ceil(count($blocks) / $processes) : self::SPKL_PDF_PAGES_PER_PART;

        foreach (array_chunk($blocks, max(1, $perPart)) as $chunk) {
            $groups = [];
            foreach ($chunk as [$group, $block]) {
                $groups[$group['title']] ??= [...$group, 'blocks' => []];
                $groups[$group['title']]['blocks'][] = $block;
            }
            $parts[] = ['view' => 'payroll.spkl', 'data' => ['document' => [...$document, 'groups' => array_values($groups)]], 'orientation' => 'portrait', 'paper' => 'A4'];
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
