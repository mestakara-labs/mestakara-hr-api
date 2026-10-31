<?php

namespace App\Services\Payroll;

use App\Support\DocumentStyle;
use Dompdf\Dompdf;
use Dompdf\Options;
use setasign\Fpdi\Fpdi;

/**
 * Mengubah view Blade / HTML menjadi PDF (dompdf). Dipakai ekspor Daftar Upah, SPKL, dan dokumen PDF lain.
 * PDF besar dibuat per bagian lalu digabung jadi satu file (writeMerged, FPDI) supaya memori tidak habis.
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
        if (count($parts) === 1) {
            return $this->write($view, $parts[0], $orientation, $paper);
        }

        $partFiles = [];
        foreach ($parts as $data) {
            $partFiles[] = $this->write($view, $data, $orientation, $paper);
            gc_collect_cycles();   // pohon dokumen dompdf berisi referensi melingkar; lepaskan sebelum bagian berikutnya
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
