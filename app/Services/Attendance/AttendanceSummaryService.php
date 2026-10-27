<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceCode;
use App\Enums\OvertimeType;
use App\Models\AttendanceDay;
use App\Models\OvertimeEntry;
use Illuminate\Support\Carbon;

/**
 * KONTRAK BACA untuk perhitungan upah (Tahap 4): ringkasan absensi & lembur per karyawan untuk satu periode.
 * Hasilnya SAMA untuk semua sumber data (impor Excel, mobile, koreksi admin) — sumber tidak ikut dibaca.
 *
 *   [
 *     '<id karyawan>' => [
 *       'codes'            => ['K' => 22, 'DW' => 0, 'I' => 1, 'S' => 0, 'M' => 0, 'C' => 0, 'L' => 4],
 *       'paid_days'        => 22,   // jumlah hari berkode K (dasar upah harian & uang makan)
 *       'regular_overtime' => [['date' => '2026-10-03', 'start_time' => '17:00', 'end_time' => '19:00', 'duration_minutes' => 120, 'description' => '...'], ...],
 *       'dw_overtime'      => [...],
 *     ],
 *   ]
 *
 * Karyawan tanpa data tetap ada di hasil (semua angka 0, daftar lembur kosong).
 */
class AttendanceSummaryService
{
    /**
     * @param  string  $period  "YYYY-MM"
     * @param  iterable<string>  $employeeIds
     * @return array<string, array{codes: array<string, int>, paid_days: int, regular_overtime: list<array<string, mixed>>, dw_overtime: list<array<string, mixed>>}>
     */
    public function forPeriod(string $period, iterable $employeeIds): array
    {
        $employeeIds = collect($employeeIds)->values()->all();
        // [awal bulan, awal bulan berikutnya): aman untuk kolom tanggal yang tersimpan dengan jam 00:00:00.
        $start = Carbon::parse($period.'-01')->toDateString();
        $nextMonth = Carbon::parse($period.'-01')->addMonth()->toDateString();

        $summary = [];
        foreach ($employeeIds as $employeeId) {
            $summary[$employeeId] = [
                'codes' => array_fill_keys(AttendanceCode::values(), 0),
                'paid_days' => 0,
                'regular_overtime' => [],
                'dw_overtime' => [],
            ];
        }

        $codeCounts = AttendanceDay::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('date', '>=', $start)
            ->where('date', '<', $nextMonth)
            ->selectRaw('employee_id, status_code, count(*) as total')
            ->groupBy('employee_id', 'status_code')
            ->toBase()
            ->get();

        foreach ($codeCounts as $count) {
            $summary[$count->employee_id]['codes'][$count->status_code] = (int) $count->total;
        }

        $overtimes = OvertimeEntry::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('date', '>=', $start)
            ->where('date', '<', $nextMonth)
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        foreach ($overtimes as $overtime) {
            $key = $overtime->overtime_type === OvertimeType::DailyWorker ? 'dw_overtime' : 'regular_overtime';
            $summary[$overtime->employee_id][$key][] = [
                'date' => $overtime->date->toDateString(),
                'start_time' => substr($overtime->start_time, 0, 5),
                'end_time' => substr($overtime->end_time, 0, 5),
                'duration_minutes' => $overtime->duration_minutes,
                'description' => $overtime->description,
            ];
        }

        foreach ($summary as $employeeId => $employeeSummary) {
            $summary[$employeeId]['paid_days'] = $employeeSummary['codes'][AttendanceCode::Work->value];
        }

        return $summary;
    }
}
