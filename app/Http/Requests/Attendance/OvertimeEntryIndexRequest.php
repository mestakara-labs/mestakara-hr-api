<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceImportType;

/**
 * Daftar lembur: ?period=YYYY-MM&type=lembur_reguler|lembur_dw
 */
class OvertimeEntryIndexRequest extends AttendancePeriodRequest
{
    protected ?array $types = [
        AttendanceImportType::RegularOvertime,
        AttendanceImportType::DailyWorkerOvertime,
    ];
}
