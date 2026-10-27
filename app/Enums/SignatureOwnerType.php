<?php

namespace App\Enums;

/** Pemilik susunan penanda tangan (signature_settings.owner_type). */
enum SignatureOwnerType: string
{
    use HasValues;

    /** Bawaan untuk semua dokumen */
    case General = 'umum';
    case PayrollGroup = 'payroll_group';
    case Division = 'division';
}
