<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceImportType;

/**
 * Unduh template: ?period=YYYY-MM&type=daftar_hadir|lembur_reguler|lembur_dw
 */
class AttendanceTemplateRequest extends AttendancePeriodRequest
{
    protected ?array $types = [
        AttendanceImportType::AttendanceList,
        AttendanceImportType::RegularOvertime,
        AttendanceImportType::DailyWorkerOvertime,
    ];
}
