<?php

namespace App\Support;

use App\Services\Payroll\PdfRenderer;

/**
 * PDF SEDERHANA A4 berisi baris-baris teks. Baris pertama dicetak sebagai judul; font Tahoma 11
 * (DocumentStyle) lewat PdfRenderer (dompdf).
 *
 *   $bytes = SimplePdf::make(['SURAT KEPUTUSAN', 'Nomor : 2A44/P/2026.10.09-015']);
 *
 * Dipakai sementara untuk SK & petikan mutasi, sampai template resmi dibuat.
 */
class SimplePdf
{
    /** @param  list<string>  $lines */
    public static function make(array $lines): string
    {
        $title = e(array_shift($lines) ?? '');
        $body = implode('', array_map(fn (string $line) => '<div class="line">'.(e($line) ?: '&nbsp;').'</div>', $lines));

        $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'body{font-family:"'.DocumentStyle::FONT.'","DejaVu Sans",sans-serif;font-size:'.DocumentStyle::FONT_SIZE.'pt;margin:30px 20px}'
            .'.company{font-weight:bold;margin-bottom:12px}'
            .'.title{font-weight:bold;font-size:14pt;margin-bottom:10px}'
            .'.line{white-space:pre-wrap;line-height:1.4}'
            .'</style></head><body>'
            .'<div class="company">'.e(DocumentStyle::COMPANY).'</div>'
            ."<div class=\"title\">{$title}</div>{$body}"
            .'</body></html>';

        return app(PdfRenderer::class)->render($html);
    }
}
