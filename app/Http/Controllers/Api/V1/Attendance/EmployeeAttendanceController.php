<?php

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Enums\AttendanceSource;
use App\Enums\AttendanceWriteOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendancePeriodRequest;
use App\Http\Requests\Attendance\EmployeeAttendanceRequest;
use App\Models\Employee;
use App\Services\Attendance\AttendanceDayService;
use App\Services\Attendance\AttendanceEmployees;
use App\Services\Attendance\AttendanceOverview;
use App\Services\Attendance\AttendancePeriod;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Absensi SATU karyawan.
 *
 *   GET /employees/{id}/attendance?period=YYYY-MM   setiap tanggal periode, lembur, dan jejak perubahan
 *   PUT /employees/{id}/attendance                   KOREKSI MANUAL satu tanggal { date, status_code|null, reason }
 *
 * Koreksi manual punya prioritas tertinggi (mengalahkan impor Excel & aplikasi mobile) dan tercatat di jejak.
 */
class EmployeeAttendanceController extends Controller
{
    /**
     * Detail absensi karyawan
     */
    public function show(AttendancePeriodRequest $request, Employee $employee, AttendanceOverview $overview): JsonResponse
    {
        return ApiResponse::success($overview->employeeDetail($employee, $request->period()), 'Detail absensi karyawan.');
    }

    /**
     * Koreksi manual absensi
     *
     * `status_code: null` = kosongkan tanggal itu. Ditolak 422 kalau karyawan bukan Casual/DW, tidak aktif pada
     * tanggal itu, atau alasan kosong; 423 kalau periode upahnya dikunci.
     */
    public function update(
        EmployeeAttendanceRequest $request,
        Employee $employee,
        AttendanceDayService $dayService,
        AttendanceEmployees $attendanceEmployees,
        AttendanceOverview $overview,
    ): JsonResponse {
        $date = $request->validated('date');
        $period = new AttendancePeriod(substr($date, 0, 7));
        $period->ensureNotLocked();

        if (! $attendanceEmployees->one($employee, $period)->isActiveOn($date)) {
            throw ValidationException::withMessages(['date' => 'Karyawan tidak aktif pada tanggal '.Carbon::parse($date)->format('d/m/Y')]);
        }

        $code = $request->validated('status_code');
        $outcome = $code === null
            ? $dayService->clear($employee->id, $date, AttendanceSource::Manual, $request->validated('reason'))
            : $dayService->write($employee->id, $date, ['status_code' => $code], AttendanceSource::Manual, $request->validated('reason'));

        $message = match ($outcome) {
            AttendanceWriteOutcome::Unchanged => 'Tidak ada perubahan.',
            AttendanceWriteOutcome::Deleted => 'Absensi tanggal itu berhasil dikosongkan.',
            default => 'Absensi berhasil dikoreksi.',
        };

        return ApiResponse::success([
            'outcome' => $outcome->value,
            ...$overview->employeeDetail($employee, $period),
        ], $message);
    }
}
