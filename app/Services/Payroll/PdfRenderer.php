<?php

namespace App\Services\Payroll;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Mengubah view Blade menjadi FILE PDF (dompdf). Dipakai ekspor Daftar Upah & SPKL.
 */
class PdfRenderer
{
    /**
     * @param  array<string, mixed>  $data
     * @param  string  $orientation  "landscape" / "portrait"
     * @return string path file sementara
     */
    public function write(string $view, array $data, string $orientation): string
    {
        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setChroot(resource_path('views'));

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view($view, $data)->render(), 'UTF-8');
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        $path = tempnam(sys_get_temp_dir(), 'pdf-');
        file_put_contents($path, $dompdf->output());

        return $path;
    }
}
