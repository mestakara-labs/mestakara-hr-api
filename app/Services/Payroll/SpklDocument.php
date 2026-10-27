<?php

namespace App\Services\Payroll;

use App\Enums\OvertimeType;
use App\Enums\PayrollGroupBy;
use App\Enums\SignatureDocument;
use App\Models\OvertimeSetting;
use App\Services\Attendance\AttendanceSummaryService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ISI DOKUMEN SPKL (Daftar Lembur) — SATU sumber untuk Excel (SpklWorkbook) dan PDF (view payroll.spkl).
 * Satu blok per karyawan, dikelompokkan per kelompok/bidang (urut kelompok lalu nama), seperti "DAFTAR LEMBUR":
 *
 *   setiap tanggal bulan itu: Hari | Tanggal | Dari | Sampai | Jumlah | Jam Pertama | Setelah Jam Pertama |
 *                             Minggu/Libur 7 Jam Pertama (selalu 0) | Uraian Pekerjaan
 *   Jumlah, "kali" (pengali Pengaturan Lembur), hasil kali, dan
 *   "Jumlah jam yang dibayar : X x Rp. <tarif> = Rp <upah>"
 *
 * Upah = upah lembur jenis itu di daftar upah (dijumlah per tanggal yang sudah dibulatkan), jadi bisa sedikit
 * berbeda dari X × tarif. Tanpa employee_ids: hanya karyawan yang punya lembur jenis itu.
 */
class SpklDocument
{
    public function __construct(private AttendanceSummaryService $summaryService) {}

    /**
     * @param  list<string>|null  $employeeIds  null = semua karyawan yang punya lembur jenis itu
     * @return array{file_name: callable, is_draft: bool, month_label: string, title: string, groups: list<array{name: string, title: string, blocks: list<array<string, mixed>>}>}
     */
    public function build(PayrollData $data, OvertimeType $type, PayrollGroupBy $groupBy, ?string $groupKey, ?array $employeeIds): array
    {
        $groups = PayrollExportText::selectGroups(PayrollGroups::build($data->rows, $groupBy, $data->signatures), $groupKey);
        $summary = $this->summaryService->forPeriod($data->period->period, array_keys($data->rows));
        $entriesKey = $type === OvertimeType::DailyWorker ? 'dw_overtime' : 'regular_overtime';
        $payKey = $type === OvertimeType::DailyWorker ? 'dw_overtime_pay' : 'regular_overtime_pay';
        $settings = OvertimeSetting::current();
        $signDate = PayrollExportText::signDate($data->period->period);
        $usedTitles = [];
        $result = [];

        foreach ($groups as $group) {
            $signature = $data->signatures->resolve(PayrollGroups::ownerType($groupBy), $group['owner_id'], SignatureDocument::Spkl);
            $blocks = [];

            foreach ($group['rows'] as $row) {
                $entries = $summary[$row['employee_id']][$entriesKey] ?? [];
                $isSelected = $employeeIds === null ? $entries !== [] : in_array($row['employee_id'], $employeeIds, true);

                if ($isSelected) {
                    $blocks[] = $this->block($data, $row, $entries, $row[$payKey], $settings, [
                        'place_date' => "{$signature['place']}, {$signDate}",
                        'sections' => $signature['sections'],
                    ], count($blocks) + 1);
                }
            }

            if ($blocks !== []) {
                $result[] = ['name' => $group['name'], 'title' => PayrollExportText::sheetTitle($group['name'], $usedTitles), 'blocks' => $blocks];
            }
        }

        if ($result === []) {
            throw ValidationException::withMessages(['period' => 'Tidak ada lembur '.($type === OvertimeType::DailyWorker ? 'DW' : 'reguler').' untuk dicetak pada pilihan ini']);
        }

        $typeLabel = $type === OvertimeType::DailyWorker ? 'DW' : 'Reguler';
        $groupName = $groupKey === null ? null : $groups[0]['name'];
        $monthLabel = $data->period->label();

        return [
            'is_draft' => ! $data->locked,
            'month_label' => $monthLabel,
            'title' => $type === OvertimeType::DailyWorker ? 'DAFTAR LEMBUR DW' : 'DAFTAR LEMBUR',
            'file_name' => fn (string $extension) => PayrollExportText::fileName("SPKL Lembur {$typeLabel}", $groupBy, $groupName, $monthLabel, ! $data->locked, $extension),
            'groups' => $result,
        ];
    }

    /** Satu blok karyawan. */
    private function block(PayrollData $data, array $row, array $entries, int $pay, OvertimeSetting $settings, array $signature, int $number): array
    {
        $entriesByDate = [];
        foreach ($entries as $entry) {
            $entriesByDate[$entry['date']][] = $entry;
        }

        $days = [];
        $totals = ['hours' => 0.0, 'first' => 0.0, 'next' => 0.0, 'holiday' => 0];
        foreach ($data->period->dates() as $date) {
            $dateEntries = $entriesByDate[$date] ?? [];
            $hours = array_sum(array_column($dateEntries, 'duration_minutes')) / 60;
            $first = min($hours, 1);
            $next = max($hours - 1, 0);

            $days[] = [
                'day_name' => PayrollExportText::DAY_NAMES[Carbon::parse($date)->dayOfWeek],
                'date' => (int) substr($date, 8, 2),
                'from' => implode(', ', array_column($dateEntries, 'start_time')),
                'to' => implode(', ', array_column($dateEntries, 'end_time')),
                'hours' => $dateEntries === [] ? null : $hours,
                'first' => $dateEntries === [] ? null : $first,
                'next' => $dateEntries === [] ? null : $next,
                'holiday' => $dateEntries === [] ? null : 0,
                'description' => implode('; ', array_unique(array_filter(array_column($dateEntries, 'description')))),
            ];
            $totals['hours'] += $hours;
            $totals['first'] += $first;
            $totals['next'] += $next;
        }

        $products = [
            'first' => $totals['first'] * $settings->first_hour_multiplier,
            'next' => $totals['next'] * $settings->next_hour_multiplier,
            'holiday' => 0,
        ];

        return [
            'no' => $number,
            'name' => $row['name'],
            'grade' => $row['employment_status'],
            'section' => $row['position'] ?? '-',
            'base_salary' => $row['base_salary'],
            'hourly_rate' => (int) round($row['base_salary'] / max($settings->hourly_divisor, 1)),
            'days' => $days,
            'totals' => $totals,
            'multipliers' => ['first' => $settings->first_hour_multiplier, 'next' => $settings->next_hour_multiplier],
            'products' => $products,
            'paid_hours' => $products['first'] + $products['next'],
            'pay' => $pay,
            'signature' => $signature,
        ];
    }

    /** Angka jam tampil, mis. 1,5 (tanpa nol di belakang). */
    public static function hours(?float $hours): string
    {
        return $hours === null ? '' : rtrim(rtrim(number_format($hours, 2, ',', '.'), '0'), ',');
    }
}
