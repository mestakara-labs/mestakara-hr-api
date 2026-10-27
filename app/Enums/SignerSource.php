<?php

namespace App\Enums;

/**
 * Asal penandatangan dokumen (*.signer_source).
 *
 *   Unit             pilih pejabat dari penugasan AKTIF di unit ini (mis. Manager)
 *   Kantor Regional  pejabat di luar data unit: nama & jabatan DIKETIK
 *   Head Office      sama seperti Kantor Regional
 */
enum SignerSource: string
{
    use HasValues;

    case Unit = 'Unit';
    case Regional = 'Kantor Regional';
    case HeadOffice = 'Head Office';
}
