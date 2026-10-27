<?php

namespace App\Enums;

/** Jenis lembur (overtime_entries.overtime_type). */
enum OvertimeType: string
{
    use HasValues;

    case Regular = 'reguler';
    /** Lembur Daily Worker */
    case DailyWorker = 'dw';
}
