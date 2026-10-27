<?php

namespace App\Enums;

/** Dokumen yang ditandatangani (signature_sections.document). */
enum SignatureDocument: string
{
    use HasValues;

    case Payroll = 'daftar_upah';
    /** Surat Perintah Kerja Lembur */
    case Spkl = 'spkl';
}
