<?php

namespace App\Enums;

/** Jenis file Excel yang diunggah (attendance_import_batches.import_type). */
enum AttendanceImportType: string
{
    use HasValues;

    case AttendanceList = 'daftar_hadir';
    case RegularOvertime = 'lembur_reguler';
    case DailyWorkerOvertime = 'lembur_dw';
}
