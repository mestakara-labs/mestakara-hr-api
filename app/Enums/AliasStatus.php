<?php

namespace App\Enums;

/**
 * Status pemetaan penulisan asli ke data standar (nomenclature_aliases.status):
 *
 *   Terpetakan       sudah punya padanan standar
 *   Belum Dipetakan  belum punya padanan (mis. nama lokasi umum seperti "AGRO GUM")
 *   Perlu Review     padanannya perlu dicek ulang
 */
enum AliasStatus: string
{
    use HasValues;

    case Mapped = 'Terpetakan';
    case Unmapped = 'Belum Dipetakan';
    case NeedsReview = 'Perlu Review';
}
