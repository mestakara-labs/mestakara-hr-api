<?php

namespace App\Support;

/**
 * PDF SEDERHANA satu halaman A4 berisi baris-baris teks (tanpa library tambahan).
 * Sama dengan front end (src/lib/mock/sample-pdf.ts). Baris pertama dicetak sebagai judul.
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
        $lines = array_map(self::escape(...), $lines);
        $title = array_shift($lines) ?? '';

        $content = implode("\n", [
            'BT',
            '/F1 16 Tf 56 790 Td 24 TL',
            "({$title}) Tj T*",
            '/F1 11 Tf 16 TL',
            ...array_map(fn (string $line) => "({$line}) Tj T*", $lines),
            'ET',
        ]);

        // Isi PDF = daftar "objek" bernomor: katalog, daftar halaman, halaman, isi halaman, dan font.
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        // Tulis setiap objek sambil mencatat posisinya (byte ke berapa) untuk tabel "xref".
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }

    /** Teks PDF ditulis di antara tanda kurung, jadi \ ( ) di-escape. Huruf non-ASCII dibuang. */
    private static function escape(string $text): string
    {
        $text = preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
