<?php

namespace App\Http\Requests\Attendance;

/**
 * Periode + kategori karyawan: ?period=YYYY-MM&employee_category=casual|daily_worker
 * (riwayat unggahan & ringkasan daftar hadir).
 */
class AttendanceCategoryRequest extends AttendancePeriodRequest
{
    protected bool $requiresCategory = true;
}
