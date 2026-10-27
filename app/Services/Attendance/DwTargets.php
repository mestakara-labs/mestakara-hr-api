<?php

namespace App\Services\Attendance;

use App\Enums\OvertimeSource;

/**
 * DAFTAR TARGET LEMBUR DW satu periode (GET /overtime-dw/targets): karyawan Casual & DW yang punya hari DW
 * ATAU punya lembur DW, dengan target, upah lembur DW sekarang, selisih, status, dan jumlah baris per sumber.
 * Aturan hitungnya di DwPay.
 */
class DwTargets
{
    public function __construct(private AttendanceEmployees $attendanceEmployees) {}

    /** @return list<array> */
    public function forPeriod(AttendancePeriod $period): array
    {
        $data = DwPeriodData::load($period, $this->attendanceEmployees);
        $pay = DwPay::make();
        $rows = [];

        foreach ($data->employees as $id => $item) {
            $dwDays = $data->dwDays($id);
            $entries = $data->dwEntries($id);
            if ($dwDays === 0 && $entries === []) {
                continue;
            }

            $scheme = $data->scheme($item);
            $target = $scheme === null ? 0 : $pay->target($scheme, $dwDays);
            $dwPay = $scheme === null ? 0 : $pay->payForEntries($scheme, $entries);
            $sources = array_fill_keys(OvertimeSource::values(), 0);
            foreach ($entries as $entry) {
                $sources[$entry['source']]++;
            }

            $rows[] = [
                ...$item->summary(),
                'position' => $item->positionTitle(),
                'sub_unit' => $item->subUnit(),
                'placement_objects' => $item->placementObjects(),
                'payroll_group' => $item->payrollIdentity()['payroll_group'],
                'cost_center' => $item->payrollIdentity()['cost_center'],
                'wage_type' => $scheme?->wage_type->value,
                'dw_days' => $dwDays,
                'daily_rate' => $scheme?->dailyRate(),
                'target_amount' => $target,
                'dw_entries' => count($entries),
                'dw_minutes' => array_sum(array_column($entries, 'duration_minutes')),
                'dw_pay' => $dwPay,
                'difference' => $dwPay - $target,
                'status' => $pay->status($scheme, $dwDays, count($entries), $dwPay, $target),
                'sources' => [
                    'import_excel' => $sources[OvertimeSource::ImportExcel->value],
                    'rekomendasi_sistem' => $sources[OvertimeSource::SystemRecommendation->value],
                    'manual' => $sources[OvertimeSource::Manual->value],
                    'mobile' => $sources[OvertimeSource::Mobile->value],
                ],
            ];
        }

        return $rows;
    }
}
