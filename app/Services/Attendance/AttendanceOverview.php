<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceImportType;
use App\Enums\ImportBatchStatus;
use App\Enums\OvertimeType;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayChange;
use App\Models\AttendanceDayChangeItem;
use App\Models\AttendanceImportConflict;
use App\Models\Employee;
use App\Models\OvertimeEntry;
use Illuminate\Support\Carbon;

/**
 * DATA BACA halaman Unggah Absensi:
 *
 *   summaries()      tab Daftar Hadir: per karyawan jumlah kode, hari kosong, lembur, konflik, peringatan
 *   overtimeList()   tab Lembur Reguler / Lembur DW: semua lembur jenis itu + peringatannya
 *   employeeDetail() detail satu karyawan: setiap tanggal periode, lembur, dan jejak perubahan
 */
class AttendanceOverview
{
    public function __construct(
        private AttendanceEmployees $attendanceEmployees,
        private AttendanceSummaryService $summaryService,
    ) {}

    /** @return list<array> */
    public function summaries(AttendancePeriod $period): array
    {
        $employees = $this->attendanceEmployees->forPeriod($period);
        $summary = $this->summaryService->forPeriod($period->period, $employees->keys());
        $codedDates = $this->codesByEmployee($period);
        $conflicts = AttendanceImportConflict::query()
            ->whereHas('batch', fn ($query) => $query->where('period', $period->period)
                ->where('import_type', AttendanceImportType::AttendanceList)
                ->where('status', ImportBatchStatus::Active))
            ->selectRaw('employee_id, count(*) as total')
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id');

        return $employees->map(function (PeriodEmployee $item, string $id) use ($summary, $codedDates, $conflicts) {
            $codes = $summary[$id]['codes'];
            $blankDays = count(array_filter($item->activeDates(), fn (string $date) => ! isset($codedDates[$id][$date])));
            $conflictCount = (int) ($conflicts[$id] ?? 0);

            $warnings = [];
            if ($item->capDays() !== null && $codes['K'] > $item->capDays()) {
                $warnings[] = "K melebihi batas {$item->capDays()} hari";
            }
            if ($blankDays > 0) {
                $warnings[] = "{$blankDays} hari aktif tanpa kode";
            }
            if ($conflictCount > 0) {
                $warnings[] = "{$conflictCount} tanggal konflik dengan data mobile/manual";
            }

            return [
                ...$item->summary(),
                'sub_unit' => $item->subUnit(),
                'placement_objects' => $item->placementObjects(),
                ...$item->payrollIdentity(),
                'codes' => $codes,
                'blank_days' => $blankDays,
                'regular_overtime' => $this->overtimeTotals($summary[$id]['regular_overtime']),
                'dw_overtime' => $this->overtimeTotals($summary[$id]['dw_overtime']),
                'conflict_count' => $conflictCount,
                'warnings' => $warnings,
            ];
        })->values()->all();
    }

    /** @return list<array> */
    public function overtimeList(AttendancePeriod $period, OvertimeType $type): array
    {
        $employees = $this->attendanceEmployees->forPeriod($period);
        $codes = $this->codesByEmployee($period);
        [$from, $to] = OvertimeRules::weekBounds($period);
        $all = OvertimeEntry::query()
            ->with('employee:id,registration_number,name,employment_status')
            ->where('date', '>=', $from)
            ->where('date', '<', Carbon::parse($to)->addDay()->toDateString())
            ->get();

        $perEmployee = [];
        foreach ($all as $entry) {
            $perEmployee[$entry->employee_id][] = $this->entryArray($entry);
        }

        $rules = OvertimeRules::make();

        return $all
            ->filter(fn (OvertimeEntry $entry) => $entry->overtime_type === $type && $period->contains($entry->date))
            ->sortBy(fn (OvertimeEntry $entry) => [$entry->date->toDateString(), $entry->employee->name, $entry->start_time])
            ->map(function (OvertimeEntry $entry) use ($employees, $codes, $perEmployee, $rules) {
                $date = $entry->date->toDateString();
                $item = $employees->get($entry->employee_id);
                $code = $codes[$entry->employee_id][$date] ?? null;
                $warnings = $rules->limitWarnings($perEmployee[$entry->employee_id], $date);
                if ($message = OvertimeRules::dayCodeError($code) ?? OvertimeRules::dayCodeWarning($code)) {
                    $warnings[] = $message;
                }

                return [
                    'id' => $entry->id,
                    'date' => $date,
                    'start_time' => substr($entry->start_time, 0, 5),
                    'end_time' => substr($entry->end_time, 0, 5),
                    'duration_minutes' => $entry->duration_minutes,
                    'crosses_midnight' => OvertimeRules::crossesMidnight($entry->start_time, $entry->end_time),
                    'description' => $entry->description,
                    'source' => $entry->source->value,
                    'employee' => [
                        'id' => $entry->employee->id,
                        'registration_number' => $entry->employee->registration_number,
                        'name' => $entry->employee->name,
                        'employment_status' => $entry->employee->employment_status,
                    ],
                    'sub_unit' => $item?->subUnit(),
                    'placement_objects' => $item?->placementObjects() ?? [],
                    ...($item?->payrollIdentity() ?? ['position' => null, 'payroll_group' => null, 'cost_center' => null]),
                    'warnings' => $warnings,
                ];
            })
            ->values()
            ->all();
    }

    public function employeeDetail(Employee $employee, AttendancePeriod $period): array
    {
        $item = $this->attendanceEmployees->one($employee, $period);
        $days = AttendanceDay::query()
            ->with('updater:id,name')
            ->where('employee_id', $employee->id)
            ->where('date', '>=', $period->start()->toDateString())
            ->where('date', '<', $period->end()->addDay()->toDateString())
            ->get()
            ->keyBy(fn (AttendanceDay $day) => $day->date->toDateString());

        $overtime = OvertimeEntry::query()
            ->where('employee_id', $employee->id)
            ->where('date', '>=', $period->start()->toDateString())
            ->where('date', '<', $period->end()->addDay()->toDateString())
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $changes = AttendanceDayChange::query()
            ->with(['items', 'changer:id,name'])
            ->where('employee_id', $employee->id)
            ->where('date', '>=', $period->start()->toDateString())
            ->where('date', '<', $period->end()->addDay()->toDateString())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return [
            ...$item->summary(),
            'days' => array_map(function (string $date) use ($days, $item) {
                $day = $days->get($date);

                return [
                    'date' => $date,
                    'status_code' => $day?->status_code->value,
                    'source' => $day?->source->value,
                    'is_active' => $item->isActiveOn($date),
                    'notes' => $day?->notes,
                    'updated_by' => $day?->updater?->name,
                    'updated_at' => $day?->updated_at?->toIso8601String(),
                ];
            }, $period->dates()),
            'overtime' => $overtime->map(fn (OvertimeEntry $entry) => [
                'id' => $entry->id,
                'date' => $entry->date->toDateString(),
                'start_time' => substr($entry->start_time, 0, 5),
                'end_time' => substr($entry->end_time, 0, 5),
                'duration_minutes' => $entry->duration_minutes,
                'overtime_type' => $entry->overtime_type->value,
                'description' => $entry->description,
                'source' => $entry->source->value,
            ])->all(),
            'changes' => $changes->map(fn (AttendanceDayChange $change) => [
                'id' => $change->id,
                'date' => $change->date?->toDateString(),
                'action' => $change->action->value,
                'source' => $change->source->value,
                'reason' => $change->reason,
                'changed_by' => $change->changer?->name,
                'created_at' => $change->created_at?->toIso8601String(),
                'items' => $change->items->map(fn (AttendanceDayChangeItem $changeItem) => [
                    'field' => $changeItem->field,
                    'old_value' => $changeItem->old_value,
                    'new_value' => $changeItem->new_value,
                ])->all(),
            ])->all(),
        ];
    }

    /** @return array<string, array<string, string>> [id karyawan][tanggal] => kode tersimpan */
    private function codesByEmployee(AttendancePeriod $period): array
    {
        $codes = [];
        AttendanceDay::query()
            ->where('date', '>=', $period->start()->toDateString())
            ->where('date', '<', $period->end()->addDay()->toDateString())
            ->get(['employee_id', 'date', 'status_code'])
            ->each(function (AttendanceDay $day) use (&$codes) {
                $codes[$day->employee_id][$day->date->toDateString()] = $day->status_code->value;
            });

        return $codes;
    }

    /** @param  list<array{duration_minutes: int}>  $entries */
    private function overtimeTotals(array $entries): array
    {
        return ['entries' => count($entries), 'minutes' => array_sum(array_column($entries, 'duration_minutes'))];
    }

    private function entryArray(OvertimeEntry $entry): array
    {
        return [
            'employee_id' => $entry->employee_id,
            'date' => $entry->date->toDateString(),
            'start_time' => substr($entry->start_time, 0, 5),
            'end_time' => substr($entry->end_time, 0, 5),
        ];
    }
}
