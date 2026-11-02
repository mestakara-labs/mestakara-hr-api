<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceImportType;

/**
 * Daftar lembur: ?period=YYYY-MM&type=lembur_reguler|lembur_dw&employee_category=casual|daily_worker
 */
class OvertimeEntryIndexRequest extends AttendancePeriodRequest
{
    protected ?array $types = [
        AttendanceImportType::RegularOvertime,
        AttendanceImportType::DailyWorkerOvertime,
    ];

    protected bool $requiresCategory = true;
}
