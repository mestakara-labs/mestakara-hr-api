<?php

namespace App\Services\Transfer;

use App\Models\Transfer;
use App\Models\TransferItem;
use App\Support\SimplePdf;
use Illuminate\Support\Carbon;

/**
 * DOKUMEN SK MUTASI dari template — SEMENTARA berupa dokumen sederhana berisi data usulan:
 *
 *   - SK Kolektif  : satu dokumen, berisi daftar semua karyawan di usulan
 *   - Petikan SK   : satu dokumen per karyawan
 *
 * Format "pdf" (PDF sederhana) atau "doc" (dokumen Word berisi HTML; Word bisa membuka & menyuntingnya).
 * Nanti diganti template resmi (mis. dengan library PDF / .docx) setelah formatnya disepakati.
 *
 * Hasilnya ['content' => isi file, 'mime' => jenis file, 'name' => nama file], atau null.
 */
class TransferDocument
{
    /**
     * @param  string|null  $itemId  kosongkan untuk SK Kolektif; isi id item untuk Petikan SK karyawan itu
     * @param  string  $format  "pdf" atau "doc"
     */
    public function make(Transfer $transfer, ?string $itemId, string $format): ?array
    {
        $transfer->loadMissing([
            'items.employee', 'items.position', 'items.subUnit', 'items.placementObjects', 'items.fromAssignment.position',
            'signerAssignment.employee', 'signerAssignment.position',
        ]);

        $items = $itemId ? $transfer->items->where('id', $itemId)->values() : $transfer->items;
        if ($items->isEmpty()) {
            return null;
        }

        $title = $itemId ? 'PETIKAN SURAT KEPUTUSAN' : 'SURAT KEPUTUSAN';
        $decreeName = $this->safeFileName($transfer->decree_number ?? 'draf');
        $baseName = $itemId
            ? "Petikan-SK-{$decreeName}-".$this->safeFileName($items[0]->employee->name)
            : "SK-{$decreeName}";

        if ($format === 'doc') {
            return ['content' => $this->word($transfer, $items->all(), $title), 'mime' => 'application/msword', 'name' => "{$baseName}.doc"];
        }

        return ['content' => SimplePdf::make($this->lines($transfer, $items->all(), $title)), 'mime' => 'application/pdf', 'name' => "{$baseName}.pdf"];
    }

    /** Baris-baris isi PDF. */
    private function lines(Transfer $transfer, array $items, string $title): array
    {
        $basisLine = $this->basisDocumentLine($transfer);
        $lines = [
            $title,
            'Nomor   : '.($transfer->decree_number ?? '-'),
            'Tanggal : '.$this->formatDate($transfer->decree_date),
            "Tentang : {$transfer->type->value} Karyawan",
            '',
        ];

        if ($basisLine !== null) {
            array_push($lines, "Memperhatikan: {$basisLine}", '');
        }

        array_push($lines, 'MEMUTUSKAN, menetapkan perubahan jabatan berikut,', 'berlaku mulai '.$this->formatDate($transfer->effective_date).':', '');

        foreach ($items as $index => $item) {
            $lines[] = ($index + 1).". {$item->employee->name} (NIK ".($item->employee->nik ?? '-').')';
            $lines[] = '    '.$this->changeLine($item);
        }

        array_push($lines, '', 'Ditetapkan oleh,', $this->signerLine($transfer), '', 'Dokumen sementara. Template resmi menyusul.');

        return $lines;
    }

    /** Dokumen Word (.doc) berisi HTML: judul, keterangan SK, tabel karyawan, dan penandatangan. */
    private function word(Transfer $transfer, array $items, string $title): string
    {
        $escape = fn (?string $text) => e($text ?? '-');
        $basisLine = $this->basisDocumentLine($transfer);

        $rows = '';
        foreach ($items as $index => $item) {
            $rows .= '<tr><td>'.($index + 1).'</td>'
                .'<td>'.$escape($item->employee->name).'<br/>NIK '.$escape($item->employee->nik).'</td>'
                .'<td>'.$escape($item->fromAssignment?->position->title ?? 'Belum memiliki jabatan').'</td>'
                .'<td>'.$escape($item->position?->title).' ('.$escape($item->assignment_status).')</td>'
                .'<td>'.$escape($this->placeText($item)).'</td></tr>';
        }

        return '<html xmlns:w="urn:schemas-microsoft-com:office:word"><head><meta charset="utf-8"/>'
            .'<style>body{font-family:Arial;font-size:11pt}table{border-collapse:collapse;width:100%}td,th{border:1px solid #000;padding:4px}</style>'
            .'</head><body>'
            .'<h2 style="text-align:center">'.$escape($title).'</h2>'
            .'<p style="text-align:center">Nomor: '.$escape($transfer->decree_number).'<br/>Tentang: '.$escape($transfer->type->value).' Karyawan</p>'
            .($basisLine ? '<p>Memperhatikan: '.$escape($basisLine).'</p>' : '')
            .'<p>Menetapkan perubahan jabatan berikut, berlaku mulai '.$escape($this->formatDate($transfer->effective_date)).':</p>'
            .'<table><tr><th>No</th><th>Nama / NIK</th><th>Jabatan Lama</th><th>Jabatan Baru</th><th>Sub Unit / Objek</th></tr>'.$rows.'</table>'
            .'<p>Ditetapkan tanggal '.$escape($this->formatDate($transfer->decree_date)).'</p>'
            .'<p><br/><br/><br/>'.$escape($this->signerLine($transfer)).'</p>'
            .'<p style="color:#888;font-size:9pt">Dokumen sementara. Template resmi menyusul.</p>'
            .'</body></html>';
    }

    /** "Admin Gudang -> Admin Pengadaan (Definitif), Kantor Induk - Bungalow" */
    private function changeLine(TransferItem $item): string
    {
        $from = $item->fromAssignment?->position->title ?? 'Belum memiliki jabatan';

        return "{$from} -> ".($item->position?->title ?? '-')." ({$item->assignment_status}), ".$this->placeText($item);
    }

    /** "Agrowisata Rancabali - Bungalow, Pondokan", atau sub unit saja kalau tanpa objek. */
    private function placeText(TransferItem $item): string
    {
        return collect([$item->subUnit?->name, $item->placementObjects->pluck('name')->join(', ')])->filter()->join(' - ');
    }

    /** Dokumen dasar yang dikutip, mis. "Memo No. 007 tanggal 1 Oktober 2026". null = tanpa dokumen dasar. */
    private function basisDocumentLine(Transfer $transfer): ?string
    {
        if (! $transfer->basis_document_type || ! $transfer->basis_document_number) {
            return null;
        }

        $date = $transfer->basis_document_date ? ' tanggal '.$this->formatDate($transfer->basis_document_date) : '';

        return "{$transfer->basis_document_type} No. {$transfer->basis_document_number}{$date}";
    }

    private function signerLine(Transfer $transfer): string
    {
        $name = $transfer->signerAssignment?->employee->name ?? $transfer->signer_name ?? '-';
        $position = $transfer->signerAssignment?->position->title ?? $transfer->signer_position ?? '';

        return trim("{$name} - {$position}", ' -');
    }

    /** 2026-10-09 -> "9 Oktober 2026". */
    private function formatDate(?Carbon $date): string
    {
        return $date ? $date->locale('id')->translatedFormat('j F Y') : '-';
    }

    /** "2A44/P/2026.10.09-015" -> "2A44-P-2026.10.09-015" (aman untuk nama file). */
    private function safeFileName(string $text): string
    {
        return preg_replace('/[^\w.-]+/', '-', $text) ?? $text;
    }
}
