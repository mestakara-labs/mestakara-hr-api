<?php

namespace App\Services\Payroll;

use App\Enums\EmployeeCategory;
use App\Enums\PayrollDocument;
use App\Enums\PayrollGroupBy;
use App\Enums\SignatureDocument;
use App\Enums\SignatureOwnerType;
use App\Models\OvertimeSetting;
use App\Services\Attendance\AttendanceSummaryService;
use Illuminate\Support\Carbon;

/**
 * ISI DOKUMEN SPKL (Daftar Lembur) — SATU sumber untuk Excel (SpklWorkbook) dan PDF (view payroll.spkl + REKAP di
 * view payroll.daftar-upah). Hanya karyawan kategori yang dipilih:
 *
 *   spkl_reguler / spkl_dw   lembur jenis itu
 *   spkl_all                 Reguler DAN DW dalam SATU blok per karyawan (hanya Casual)
 *
 * Susunan: REKAP (satu baris per kelompok: jumlah karyawan, total jam, jam dibayar, upah lembur; penanda tangan Umum),
 * lalu blok per karyawan dikelompokkan per kelompok/bidang (urut kelompok lalu nama), seperti "DAFTAR LEMBUR":
 *
 *   setiap tanggal bulan itu (tanggal yang punya lembur Reguler & DW = satu baris per jenis, urut jenis):
 *     Hari | Tanggal | Dari | Sampai | Jumlah | Jam Pertama | Setelah Jam Pertama |
 *     Minggu/Libur: 7 Jam Pertama, Setelah 8 Jam Pertama (×3), Setelah 9 Jam Kedua (×4) — selalu 0 |
 *     Uraian Pekerjaan | (Excel spkl_all: Jenis)
 *   baris Jumlah (per kolom pengali), Pengali, Total Jam (jumlah × pengali), lalu
 *   "Jumlah Jam yang Dibayar : | X x Rp. <tarif> | = Rp. <upah>"
 *   Jam bernilai 0 ditulis "-".
 *
 * Upah = upah lembur jenis itu di daftar upah (spkl_all: reguler + DW), jadi bisa sedikit berbeda dari X × tarif.
 * Tanpa employee_ids: hanya karyawan yang punya lembur jenis itu (spkl_all: reguler ATAU DW).
 * REKAP Excel spkl_all: setiap kelompok dipecah jadi baris Reguler & DW (jenis tanpa lembur dilewati); JUMLAH karyawan
 * dihitung unik. PDF: satu baris per kelompok.
 */
class SpklDocument
{
    public const TITLE = 'DAFTAR LEMBUR';

    /** Pengali kolom Minggu/Libur: [kunci => pengali]. Lembur hari libur belum dihitung (jamnya selalu 0). */
    public const HOLIDAY_MULTIPLIERS = ['holiday_7' => 2, 'holiday_8' => 3, 'holiday_9' => 4];

    /** [jenis lembur => [kunci lembur di ringkasan absensi, kunci upah di baris daftar upah, label Jenis]] */
    private const TYPES = [
        'reguler' => ['regular_overtime', 'regular_overtime_pay', 'Reguler'],
        'dw' => ['dw_overtime', 'dw_overtime_pay', 'DW'],
    ];

    /** Kolom REKAP SPKL; Excel spkl_all menyisipkan kolom Jenis sesudah URAIAN. */
    private const REKAP_COLUMNS = [
        ['no', 'No', 5, false],
        ['name', 'URAIAN', 28, false],
        ['employee_count', 'Jumlah Karyawan', 10, false],
        ['total_hours', 'Total Jam', 10, 'hours'],
        ['paid_hours', 'Jam Dibayar', 10, 'hours'],
        ['pay', 'Upah Lembur', 14, true],
    ];

    private const TYPE_COLUMN = ['type', 'Jenis', 9, false];

    public function __construct(private AttendanceSummaryService $summaryService) {}

    /**
     * @param  list<string>|null  $groupKeys  null = semua kelompok
     * @param  list<string>|null  $employeeIds  null = semua karyawan yang punya lembur jenis itu
     * @return array<string, mixed> { file_name, is_draft, month_label, title, show_type, rekap, excel_rekap, groups }
     */
    public function build(PayrollData $data, PayrollDocument $document, PayrollGroupBy $groupBy, ?array $groupKeys, ?array $employeeIds, EmployeeCategory $category, PayrollExportOptions $options = new PayrollExportOptions): array
    {
        $types = $document->overtimeTypes();
        $groups = PayrollExportText::selectGroups(PayrollGroups::build($data->rows, $groupBy, $data->signatures), $groupKeys, $category);
        $ids = array_merge(...array_map(fn (array $group) => array_column($group['rows'], 'employee_id'), $groups ?: [['rows' => []]]));
        $summary = $this->summaryService->forPeriod($data->period->period, $ids);
        $settings = OvertimeSetting::current();
        $signDate = $options->signDateText($data->period->period);
        $usedTitles = [];
        $result = [];

        foreach ($groups as $group) {
            $signature = $data->signatures->resolve(PayrollGroups::ownerType($groupBy), $group['owner_id'], SignatureDocument::Spkl);
            $blocks = [];

            foreach ($group['rows'] as $row) {
                $entriesByType = [];
                foreach ($types as $type) {
                    $entriesByType[$type->value] = $summary[$row['employee_id']][self::TYPES[$type->value][0]] ?? [];
                }
                $hasOvertime = array_merge(...array_values($entriesByType)) !== [];
                $isSelected = $employeeIds === null ? $hasOvertime : in_array($row['employee_id'], $employeeIds, true);

                if ($isSelected) {
                    $blocks[] = $this->block($data, $row, $entriesByType, $settings, [
                        'place_date' => "{$signature['place']}, {$signDate}",
                        'sections' => $signature['sections'],
                    ], count($blocks) + 1);
                }
            }

            if ($blocks !== []) {
                $result[] = ['name' => $group['name'], 'blocks' => $blocks];
            }
        }

        if ($result === []) {
            throw PayrollExportText::nothingToExport($category, $document);
        }

        // Judul sama untuk semua jenis lembur; jenisnya terlihat di nama file (dan kolom Jenis Excel SPKL Reguler + DW).
        $title = self::TITLE;
        $rekapSignature = (new PayrollExportDocument)->signature($data->signatures, SignatureOwnerType::General, null, SignatureDocument::Spkl, $signDate);
        $rekapUsedTitles = [];
        $rekap = PayrollExportDocument::rekap($rekapUsedTitles, "REKAPITULASI {$title}", self::REKAP_COLUMNS, array_map(fn (array $group) => self::rekapRow($group['name'], $group['blocks'], null), $result), $rekapSignature);
        $excelRekap = $document === PayrollDocument::SpklAll ? $this->rekapByType($result, $title, $rekapSignature) : $rekap;
        PayrollExportText::sheetTitle('REKAP', $usedTitles);
        foreach ($result as $index => $group) {
            $result[$index]['title'] = PayrollExportText::sheetTitle($group['name'], $usedTitles);
        }

        $monthLabel = $data->period->label();
        $groupNames = array_column($result, 'name');

        $isDraft = $options->isDraft($data);

        return [
            'is_draft' => $isDraft,
            'month_label' => $monthLabel,
            'title' => $title,
            'show_type' => $document === PayrollDocument::SpklAll,
            'file_name' => fn (string $extension) => PayrollExportText::fileName($document, $category, $groupNames, $monthLabel, $isDraft, $extension),
            'rekap' => $rekap,
            'excel_rekap' => $excelRekap,
            'groups' => $result,
        ];
    }

    /** REKAP Excel spkl_all: baris Reguler & DW per kelompok; JUMLAH karyawan dihitung unik. */
    private function rekapByType(array $groups, string $title, array $signature): array
    {
        $rows = [];
        foreach ($groups as $group) {
            foreach (self::TYPES as $type => [, , $label]) {
                $blocks = array_values(array_filter($group['blocks'], fn (array $block) => isset($block['by_type'][$type])));
                if ($blocks !== []) {
                    $rows[] = ['type' => $label, ...self::rekapRow($group['name'], $blocks, $type)];
                }
            }
        }
        $columns = self::REKAP_COLUMNS;
        array_splice($columns, 2, 0, [self::TYPE_COLUMN]);

        $usedTitles = [];
        $sheet = PayrollExportDocument::rekap($usedTitles, "REKAPITULASI {$title}", $columns, $rows, $signature);
        $sheet['label_span'] = 3;
        $sheet['totals']['employee_count'] = array_sum(array_map(fn (array $group) => count($group['blocks']), $groups));

        return $sheet;
    }

    /** Satu baris REKAP dari blok-blok karyawan; $type = hanya angka jenis itu (null = semua jenis). */
    private static function rekapRow(string $name, array $blocks, ?string $type): array
    {
        $part = fn (array $block, string $key) => $type === null ? $block[$key] : $block['by_type'][$type][$key];

        return [
            'name' => $name,
            'employee_count' => count($blocks),
            'total_hours' => round(array_sum(array_map(fn (array $block) => $part($block, 'total_hours'), $blocks)), 2),
            'paid_hours' => round(array_sum(array_map(fn (array $block) => $part($block, 'paid_hours'), $blocks)), 2),
            'pay' => (int) array_sum(array_map(fn (array $block) => $part($block, 'pay'), $blocks)),
        ];
    }

    /**
     * Satu blok karyawan.
     *
     * @param  array<string, list<array>>  $entriesByType  [jenis lembur => lembur dari ringkasan absensi]
     */
    private function block(PayrollData $data, array $row, array $entriesByType, OvertimeSetting $settings, array $signature, int $number): array
    {
        $entries = [];   // [tanggal][jenis] => lembur
        foreach ($entriesByType as $type => $typeEntries) {
            foreach ($typeEntries as $entry) {
                $entries[$entry['date']][$type][] = $entry;
            }
        }

        $days = [];
        $holidayZeros = array_fill_keys(array_keys(self::HOLIDAY_MULTIPLIERS), 0);
        $totals = ['first' => 0.0, 'next' => 0.0, ...$holidayZeros];
        $byType = [];
        foreach ($data->period->dates() as $date) {
            $dayName = PayrollExportText::DAY_NAMES[Carbon::parse($date)->dayOfWeek];
            $dateNumber = (int) substr($date, 8, 2);   // tanggal saja (bulan & tahun sudah di kop)
            $dateTypes = $entries[$date] ?? [];
            if ($dateTypes === []) {
                $days[] = ['day_name' => $dayName, 'date' => $dateNumber, 'type' => null, 'from' => '', 'to' => '', 'hours' => null,
                    'first' => null, 'next' => null, ...array_map(fn () => null, $holidayZeros), 'description' => ''];

                continue;
            }

            foreach ($dateTypes as $type => $dateEntries) {
                usort($dateEntries, fn (array $a, array $b) => $a['start_time'] <=> $b['start_time']);
                $hours = array_sum(array_column($dateEntries, 'duration_minutes')) / 60;
                $first = min($hours, 1);
                $next = max($hours - 1, 0);

                $days[] = [
                    'day_name' => $dayName,
                    'date' => $dateNumber,
                    'type' => self::TYPES[$type][2],
                    'from' => implode(', ', array_column($dateEntries, 'start_time')),
                    'to' => implode(', ', array_column($dateEntries, 'end_time')),
                    'hours' => $hours,
                    'first' => $first,
                    'next' => $next,
                    ...$holidayZeros,
                    'description' => implode('; ', array_unique(array_filter(array_column($dateEntries, 'description')))),
                ];
                $totals['first'] += $first;
                $totals['next'] += $next;
                $byType[$type]['hours'] = ($byType[$type]['hours'] ?? 0) + $hours;
                $byType[$type]['first'] = ($byType[$type]['first'] ?? 0) + $first;
                $byType[$type]['next'] = ($byType[$type]['next'] ?? 0) + $next;
            }
        }

        $products = [
            'first' => $totals['first'] * $settings->first_hour_multiplier,
            'next' => $totals['next'] * $settings->next_hour_multiplier,
            ...$holidayZeros,
        ];
        $paidHours = fn (float $first, float $next) => $first * $settings->first_hour_multiplier + $next * $settings->next_hour_multiplier;

        $typeTotals = [];
        foreach ($byType as $type => $sums) {
            $typeTotals[$type] = [
                'total_hours' => $sums['hours'],
                'paid_hours' => $paidHours($sums['first'], $sums['next']),
                'pay' => (int) $row[self::TYPES[$type][1]],
            ];
        }

        return [
            'no' => $number,
            'name' => $row['name'],
            'grade' => $row['employment_status'],
            'section' => $row['position'] ?? '-',
            'base_salary' => $row['base_salary'],
            'hourly_rate' => (int) round($row['base_salary'] / max($settings->hourly_divisor, 1)),
            'days' => $days,
            'totals' => $totals,
            'multipliers' => ['first' => $settings->first_hour_multiplier, 'next' => $settings->next_hour_multiplier, ...self::HOLIDAY_MULTIPLIERS],
            'products' => $products,
            'total_hours' => array_sum(array_column($typeTotals, 'total_hours')),
            'paid_hours' => $products['first'] + $products['next'],
            'pay' => array_sum(array_map(fn (string $type) => (int) $row[self::TYPES[$type][1]], array_keys($entriesByType))),
            'by_type' => $typeTotals,
            'signature' => $signature,
        ];
    }

    /** Angka jam / pengali tampil, mis. 1,5 (tanpa nol di belakang); 0 = "-"; null = kosong. */
    public static function hours(int|float|null $hours): string
    {
        return match (true) {
            $hours === null => '',
            (float) $hours === 0.0 => '-',
            default => rtrim(rtrim(number_format($hours, 2, ',', '.'), '0'), ','),
        };
    }
}
