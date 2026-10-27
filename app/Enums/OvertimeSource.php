<?php

namespace App\Enums;

/** Asal satu baris lembur (overtime_entries.source). */
enum OvertimeSource: string
{
    use HasValues;

    case ImportExcel = 'import_excel';
    case SystemRecommendation = 'rekomendasi_sistem';
    case Mobile = 'mobile';
    case Manual = 'manual';
}
