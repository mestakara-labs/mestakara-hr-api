<?php

namespace App\Enums;

/** Asal seorang penanda tangan (signature_section_signers.source). */
enum SignatorySource: string
{
    use HasValues;

    /** Nama & jabatan diambil dari data karyawan terbaru */
    case Employee = 'karyawan';
    /** Nama & jabatan diketik */
    case Manual = 'manual';
}
