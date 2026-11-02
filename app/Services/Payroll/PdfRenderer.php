<?php

namespace App\Services\Payroll;

use App\Support\DocumentStyle;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use Throwable;

use function Illuminate\Support\php_binary;

/**
 * Mengubah view Blade / HTML menjadi PDF (dompdf). Dipakai ekspor Daftar Upah, SPKL, dan dokumen PDF lain.
 * PDF besar dibuat per bagian lalu digabung jadi satu file (writeMerged, FPDI) supaya memori tidak habis.
 *
 * Tata letak dompdf lambat (± 0,45 detik per halaman SPKL), jadi bagian-bagian PDF besar dirender BERSAMAAN di proses
 * PHP terpisah (perintah artisan hr:render-pdf): HTML tiap bagian dibuat di sini lalu ditulis ke file sementara, setiap
 * proses mengubah satu file HTML menjadi PDF. Jumlah proses: config hr.pdf_processes ("auto" = setengah inti CPU,
 * maksimal AUTO_MAX_PROCESSES; 1 = berurutan di proses ini saja), tidak lebih dari jumlah bagian.
 *
 * Hosting bersama (mis. cPanel / CloudLinux) membatasi jumlah proses & memori per akun dan bisa mematikan proses
 * (signal 9), atau tidak mengizinkan proc_open sama sekali. Karena itu render bersamaan yang gagal TIDAK menggagalkan
 * ekspor: bagian-bagian dirender ulang berurutan di proses ini (lebih lambat) dan kejadiannya dicatat di log.
 *
 * Font: Tahoma (DocumentStyle::FONT). File font Tahoma milik Microsoft TIDAK disimpan di repo; diambil dari folder
 * font sistem (FONT_DIRS) lalu didaftarkan sekali ke cache font dompdf (storage/app/dompdf-fonts). Kalau file tidak
 * ditemukan di server, PDF memakai DejaVu Sans (huruf mirip, bawaan dompdf).
 */
class PdfRenderer
{
    /** Folder yang dicari untuk tahoma.ttf & tahomabd.ttf, urut prioritas. */
    private const FONT_DIRS = [
        'C:/Windows/Fonts',
        '/usr/share/fonts/truetype/msttcorefonts',
        '/usr/share/fonts/truetype/tahoma',
        '/usr/share/fonts/TTF',
        '/Library/Fonts',
    ];

    /** Nama file Tahoma per ketebalan. */
    private const FONT_FILES = ['normal' => 'tahoma.ttf', 'bold' => 'tahomabd.ttf'];

    /** Batas memori & waktu satu proses render bagian PDF (satu bagian SPKL 25 halaman ± 150 MB, ± 15 detik). */
    private const PROCESS_MEMORY_LIMIT = '1024M';

    private const PROCESS_TIMEOUT_SECONDS = 300;

    /** Jeda pemeriksaan proses yang sedang berjalan (mikrodetik). */
    private const POLL_MICROSECONDS = 20_000;

    /**
     * Batas atas jumlah proses untuk "auto". Di hosting bersama /proc/cpuinfo menampilkan SEMUA inti server fisik
     * (bisa puluhan), bukan jatah akun; tanpa batas ini terlalu banyak proses dijalankan lalu dimatikan hosting.
     */
    public const AUTO_MAX_PROCESSES = 4;

    /**
     * @param  array<string, mixed>  $data
     * @param  string  $orientation  "landscape" / "portrait"
     * @param  string  $paper  ukuran kertas dompdf, mis. "A4", "A3"
     * @return string path file sementara
     */
    public function write(string $view, array $data, string $orientation, string $paper = 'A4'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf-');
        file_put_contents($path, $this->render(view($view, $data)->render(), $orientation, $paper));

        return $path;
    }

    /**
     * PDF besar dalam SATU file: setiap bagian dirender terpisah (memori dompdf dilepas sesudah tiap bagian),
     * lalu halaman-halamannya digabung berurutan dengan FPDI. Satu bagian = data view untuk satu render.
     *
     * @param  list<array<string, mixed>>  $parts
     * @return string path file sementara
     */
    public function writeMerged(string $view, array $parts, string $orientation, string $paper = 'A4'): string
    {
        return $this->writeParts(array_map(fn (array $data) => ['view' => $view, 'data' => $data, 'orientation' => $orientation, 'paper' => $paper], $parts));
    }

    /**
     * Sama dengan writeMerged(), tetapi setiap bagian boleh memakai view & kertas sendiri (mis. REKAP F4 mendatar lalu
     * SPKL A4 tegak).
     *
     * @param  list<array{view: string, data: array<string, mixed>, orientation: string, paper: string}>  $parts
     */
    public function writeParts(array $parts): string
    {
        if (count($parts) === 1) {
            return $this->write($parts[0]['view'], $parts[0]['data'], $parts[0]['orientation'], $parts[0]['paper']);
        }

        $processes = function_exists('proc_open') ? self::processCount(count($parts)) : 1;
        try {
            $partFiles = $processes > 1 ? $this->renderInProcesses($parts, $processes) : $this->renderInOrder($parts);
        } catch (Throwable $exception) {
            if ($processes === 1) {
                throw $exception;
            }
            // Mis. proses dimatikan hosting (signal 9) karena batas memori / jumlah proses: ulangi berurutan.
            Log::warning("Render PDF bersamaan ({$processes} proses) gagal, diulang berurutan: {$exception->getMessage()}");
            $partFiles = $this->renderInOrder($parts);
        }

        $merged = new Fpdi;
        foreach ($partFiles as $file) {
            $pageCount = $merged->setSourceFile($file);
            for ($page = 1; $page <= $pageCount; $page++) {
                $template = $merged->importPage($page);
                $size = $merged->getTemplateSize($template);
                $merged->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $merged->useTemplate($template);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'pdf-');
        $merged->Output('F', $path);
        array_map('unlink', $partFiles);

        return $path;
    }

    /**
     * Jumlah proses render untuk $partCount bagian: config hr.pdf_processes ("auto" = setengah inti CPU, maksimal
     * AUTO_MAX_PROCESSES), minimal 1, tidak lebih dari jumlah bagian. $cpuCount null = jumlah inti CPU server ini.
     */
    public static function processCount(int $partCount, ?int $cpuCount = null): int
    {
        $setting = config('hr.pdf_processes');
        $count = $setting === null || $setting === '' || $setting === 'auto'
            ? min(intdiv($cpuCount ?? self::cpuCount(), 2), self::AUTO_MAX_PROCESSES)
            : (int) $setting;

        return max(1, min($count, $partCount));
    }

    /** Jumlah inti CPU server (Windows: NUMBER_OF_PROCESSORS, Linux: /proc/cpuinfo); 1 kalau tidak diketahui. */
    private static function cpuCount(): int
    {
        if ($count = (int) getenv('NUMBER_OF_PROCESSORS')) {
            return $count;
        }
        if (is_readable('/proc/cpuinfo')) {
            return max(1, (int) preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo')));
        }

        return 1;
    }

    /**
     * Bagian dirender satu per satu di proses ini.
     *
     * @return list<string> file PDF per bagian, urut
     */
    private function renderInOrder(array $parts): array
    {
        $files = [];
        foreach ($parts as $part) {
            $files[] = $this->write($part['view'], $part['data'], $part['orientation'], $part['paper']);
            gc_collect_cycles();   // pohon dokumen dompdf berisi referensi melingkar; lepaskan sebelum bagian berikutnya
        }

        return $files;
    }

    /**
     * Bagian dirender BERSAMAAN: HTML tiap bagian dibuat di proses ini, lalu paling banyak $limit proses
     * "php artisan hr:render-pdf" berjalan sekaligus (bagian berikutnya dimulai begitu satu proses selesai).
     * Satu proses gagal = semua dihentikan dan exception (file sementara dihapus; writeParts lalu mengulang berurutan).
     *
     * @return list<string> file PDF per bagian, urut
     */
    private function renderInProcesses(array $parts, int $limit): array
    {
        $jobs = [];
        foreach ($parts as $index => $part) {
            $html = tempnam(sys_get_temp_dir(), 'pdf-html-');
            file_put_contents($html, view($part['view'], $part['data'])->render());
            $jobs[$index] = ['html' => $html, 'pdf' => tempnam(sys_get_temp_dir(), 'pdf-'), 'orientation' => $part['orientation'], 'paper' => $part['paper']];
        }

        $queue = $jobs;
        $running = [];
        try {
            while ($queue !== [] || $running !== []) {
                while ($queue !== [] && count($running) < $limit) {
                    $job = array_shift($queue);
                    $running[] = Process::path(base_path())->timeout(self::PROCESS_TIMEOUT_SECONDS)->start([
                        self::phpBinary(), '-d', 'memory_limit='.self::PROCESS_MEMORY_LIMIT, 'artisan', 'hr:render-pdf',
                        $job['html'], $job['pdf'], $job['orientation'], $job['paper'],
                    ]);
                }
                foreach ($running as $key => $process) {
                    if ($process->running()) {
                        continue;
                    }
                    $result = $process->wait();
                    unset($running[$key]);
                    if ($result->failed()) {
                        throw new RuntimeException('Render PDF gagal: '.trim($result->errorOutput() ?: $result->output()));
                    }
                }
                if ($running !== []) {
                    usleep(self::POLL_MICROSECONDS);
                }
            }
        } catch (Throwable $exception) {
            foreach ($running as $process) {
                $process->stop();
            }
            array_map(fn (array $job) => @unlink($job['pdf']), $jobs);
            throw $exception;
        } finally {
            array_map(fn (array $job) => @unlink($job['html']), $jobs);
        }

        return array_values(array_column($jobs, 'pdf'));
    }

    /** Program PHP CLI untuk proses render: config hr.php_binary, selain itu dicari otomatis. */
    private static function phpBinary(): string
    {
        return config('hr.php_binary') ?: php_binary();
    }

    /** Isi PDF (bytes) dari HTML. */
    public function render(string $html, string $orientation = 'portrait', string $paper = 'A4'): string
    {
        $fontDir = storage_path('app/dompdf-fonts');
        if (! is_dir($fontDir)) {
            mkdir($fontDir, 0775, true);
        }

        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $tahomaDir = self::tahomaDir();
        // dompdf hanya boleh membaca file di folder "chroot": view Blade + folder font Tahoma.
        $options->setChroot(array_filter([resource_path('views'), $tahomaDir]));
        $options->setFontDir($fontDir);
        $options->setFontCache($fontDir);

        $dompdf = new Dompdf($options);
        if ($tahomaDir !== null) {
            $this->registerTahoma($dompdf, $tahomaDir);
        }
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($paper, $orientation);
        $dompdf->render();

        return $dompdf->output();
    }

    /** Folder font sistem yang berisi tahoma.ttf, atau null kalau tidak ada. */
    private static function tahomaDir(): ?string
    {
        foreach (self::FONT_DIRS as $dir) {
            if (is_file($dir.'/'.self::FONT_FILES['normal'])) {
                return $dir;
            }
        }

        return null;
    }

    /** Daftarkan Tahoma (normal & tebal) ke dompdf; sekali saja, sesudahnya dipakai dari cache font. */
    private function registerTahoma(Dompdf $dompdf, string $dir): void
    {
        $metrics = $dompdf->getFontMetrics();
        $registered = $metrics->getFontFamilies()[mb_strtolower(DocumentStyle::FONT)] ?? [];

        foreach (self::FONT_FILES as $weight => $file) {
            if (! isset($registered[$weight]) && is_file("{$dir}/{$file}")) {
                $metrics->registerFont(['family' => DocumentStyle::FONT, 'weight' => $weight, 'style' => 'normal'], "{$dir}/{$file}");
            }
        }
    }
}
