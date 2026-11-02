<?php

namespace Tests\Feature\Payroll;

use App\Services\Payroll\PdfRenderer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\View;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

/**
 * PDF besar dirender per bagian lalu digabung (PdfRenderer::writeParts). Dengan config hr.pdf_processes > 1 bagian-bagian
 * dirender BERSAMAAN di proses "php artisan hr:render-pdf"; urutan halaman hasil harus tetap sama dengan urutan bagian.
 */
class PdfRendererTest extends TestCase
{
    private string $viewDir;

    /** Nilai asli NUMBER_OF_PROCESSORS (dikembalikan sesudah test). */
    private string|false $processors;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processors = getenv('NUMBER_OF_PROCESSORS');
        $this->viewDir = sys_get_temp_dir().'/pdf-renderer-test-'.uniqid();
        File::ensureDirectoryExists($this->viewDir);
        File::put("{$this->viewDir}/pdf-test-page.blade.php", '<html><body><p>Bagian {{ $label }}</p></body></html>');
        View::addLocation($this->viewDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->viewDir);
        putenv($this->processors === false ? 'NUMBER_OF_PROCESSORS' : "NUMBER_OF_PROCESSORS={$this->processors}");
        parent::tearDown();
    }

    public function test_parts_rendered_in_parallel_processes_are_merged_in_order(): void
    {
        config(['hr.pdf_processes' => 2]);

        $path = app(PdfRenderer::class)->writeParts($this->parts());

        // Ukuran halaman (mm) membuktikan urutan: A4 tegak, A4 mendatar, A5 tegak.
        $this->assertSame([[210, 297], [297, 210], [148, 210]], $this->pageSizes($path));
        unlink($path);
    }

    public function test_one_process_renders_in_order_without_extra_processes(): void
    {
        config(['hr.pdf_processes' => 1]);
        Process::fake();

        $path = app(PdfRenderer::class)->writeParts($this->parts());

        Process::assertNothingRan();
        $this->assertSame([[210, 297], [297, 210], [148, 210]], $this->pageSizes($path));
        unlink($path);
    }

    public function test_failed_render_process_stops_the_export(): void
    {
        config(['hr.pdf_processes' => 2]);
        Process::fake(['*' => Process::result(errorOutput: 'Memori habis', exitCode: 1)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Render PDF gagal: Memori habis');

        app(PdfRenderer::class)->writeParts($this->parts());
    }

    public function test_process_count_follows_setting_cpu_count_and_number_of_parts(): void
    {
        putenv('NUMBER_OF_PROCESSORS=12');

        config(['hr.pdf_processes' => 'auto']);
        $this->assertSame([6, 3, 1], [PdfRenderer::processCount(9), PdfRenderer::processCount(3), PdfRenderer::processCount(1)]);   // setengah inti CPU, maks jumlah bagian

        config(['hr.pdf_processes' => '4']);
        $this->assertSame([4, 2], [PdfRenderer::processCount(9), PdfRenderer::processCount(2)]);

        foreach (['1', '0', 'tidak-valid'] as $setting) {
            config(['hr.pdf_processes' => $setting]);
            $this->assertSame(1, PdfRenderer::processCount(9), "setting {$setting}");
        }
    }

    /** @return list<array{view: string, data: array<string, mixed>, orientation: string, paper: string}> */
    private function parts(): array
    {
        return [
            ['view' => 'pdf-test-page', 'data' => ['label' => 1], 'orientation' => 'portrait', 'paper' => 'A4'],
            ['view' => 'pdf-test-page', 'data' => ['label' => 2], 'orientation' => 'landscape', 'paper' => 'A4'],
            ['view' => 'pdf-test-page', 'data' => ['label' => 3], 'orientation' => 'portrait', 'paper' => 'A5'],
        ];
    }

    /** @return list<array{int, int}> lebar & tinggi tiap halaman (mm, dibulatkan) */
    private function pageSizes(string $path): array
    {
        $pdf = new Fpdi;
        $sizes = [];
        for ($page = 1, $count = $pdf->setSourceFile($path); $page <= $count; $page++) {
            $size = $pdf->getTemplateSize($pdf->importPage($page));
            $sizes[] = [(int) round($size['width']), (int) round($size['height'])];
        }

        return $sizes;
    }
}
