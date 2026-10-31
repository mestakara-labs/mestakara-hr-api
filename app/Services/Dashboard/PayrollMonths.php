<?php

namespace App\Services\Dashboard;

use App\Services\Attendance\AttendanceEmployees;
use App\Services\Attendance\AttendancePeriod;
use App\Services\Attendance\AttendanceSummaryService;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\PayrollData;
use Illuminate\Support\Carbon;
use ReflectionClass;

/**
 * ANGKA UPAH PER BULAN untuk dashboard & analitik — SUMBERNYA SAMA dengan Daftar Upah (PayrollData: periode dikunci
 * memakai potret, periode draf dihitung PayrollCalculator). Setiap baris dipasangkan dengan fakta karyawan pada
 * tanggal acuan bulan itu (untuk filter & dimensi selain bidang / kelompok / cost center, yang diambil dari baris).
 *
 * Satu bulan dihitung SEKALI per permintaan (memo); data karyawan dimuat sekali (PayrollCalculator yang sama).
 * Daftarkan sebagai scoped binding (lihat AppServiceProvider).
 */
class PayrollMonths
{
    /** Komponen upah: [kunci => [label, fungsi nilai dari baris datar Daftar Upah]]. */
    public const COMPONENTS = [
        'base_pay' => 'Gaji',
        'regular_overtime_pay' => 'Lembur Reguler',
        'dw_overtime_pay' => 'Lembur DW',
        'overtime_pay' => 'Lembur',
        'operational_allowance' => 'Ops & Jab',
        'incentive' => 'Insentif',
        'meal_allowance' => 'Uang Makan',
        'allowances' => 'Tunjangan',
        'deductions' => 'Potongan',
        'gross' => 'Jumlah Upah',
        'net' => 'Diterima',
    ];

    /** @var array<string, list<array{month: string, row: array<string, mixed>, facts: EmployeeFacts}>> */
    private array $rowsByMonth = [];

    private PayrollCalculator $calculator;

    public function __construct(
        AttendanceEmployees $attendanceEmployees,
        AttendanceSummaryService $summaryService,
        private WorkforceFacts $workforce,
    ) {
        // Karyawan Casual & DW dimuat sekali untuk semua bulan dalam permintaan ini.
        $this->calculator = new PayrollCalculator($attendanceEmployees->reuseLoadedEmployees(), $summaryService);
    }

    /**
     * Baris Daftar Upah satu bulan (Casual & DW yang aktif minimal satu hari), sudah disaring.
     *
     * @return list<array{month: string, row: array<string, mixed>, facts: EmployeeFacts}>
     */
    public function month(string $month, DashboardFilters $filters): array
    {
        if (! isset($this->rowsByMonth[$month])) {
            $period = new AttendancePeriod($month);
            $date = $period->referenceDate();
            $items = [];
            foreach (PayrollData::load($period, $this->calculator)->rows as $employeeId => $row) {
                $items[] = ['month' => $month, 'row' => $row, 'facts' => $this->lazyFacts($date, $employeeId)];
            }
            $this->rowsByMonth[$month] = $items;
        }

        return array_values(array_filter(
            $this->rowsByMonth[$month],
            fn (array $item) => $filters->matches($item['facts'], $item['row']),
        ));
    }

    /**
     * Semua baris dalam rentang, urut bulan.
     *
     * @return list<array{month: string, row: array<string, mixed>, facts: EmployeeFacts}>
     */
    public function range(DashboardPeriod $period, DashboardFilters $filters): array
    {
        return array_merge(...array_map(fn (string $month) => $this->month($month, $filters), $period->months()) ?: [[]]);
    }

    /**
     * Fakta karyawan yang baru DIHITUNG saat pertama dipakai (filter / dimensi). Total upah tanpa filter, mis. KPI
     * rentang pembanding, jadi tidak perlu menghitung fakta sama sekali.
     */
    private function lazyFacts(Carbon $date, string $employeeId): EmployeeFacts
    {
        return (new ReflectionClass(EmployeeFacts::class))->newLazyProxy(
            fn () => $this->workforce->forEmployees($date, [$employeeId])[$employeeId],
        );
    }

    /** Nilai satu komponen dari baris datar Daftar Upah (lihat COMPONENTS). */
    public static function value(array $row, string $component): int
    {
        return match ($component) {
            'allowances' => $row['operational_allowance'] + $row['incentive'] + $row['meal_allowance'],
            'deductions' => $row['deductions_total'],
            default => (int) $row[$component],
        };
    }

    /**
     * Jumlah komponen beberapa baris.
     *
     * @param  iterable<array{row: array<string, mixed>}>  $items
     */
    public static function sum(iterable $items, string $component): int
    {
        $total = 0;
        foreach ($items as $item) {
            $total += self::value($item['row'], $component);
        }

        return $total;
    }
}
