<?php

namespace App\Enums;

/**
 * Cara menyiapkan dokumen SK mutasi (transfers.document_mode).
 *
 *   Unggah    sudah punya SK bertanda tangan -> diunggah, usulan langsung Berlaku
 *   Template  SK dibuat dari template -> Menunggu Dokumen sampai scan bertanda tangan diunggah
 */
enum DocumentMode: string
{
    use HasValues;

    case Upload = 'Unggah';
    case Template = 'Template';
}
