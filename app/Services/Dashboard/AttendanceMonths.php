<?php

namespace App\Services\Dashboard;

use App\Services\Attendance\AttendanceSummaryService;

/**
 * KEHADIRAN & LEMBUR PER BULAN untuk dashboard (Casual & Daily Worker = karyawan di Daftar Upah bulan itu).
 * Jumlah kode & daftar lembur dari AttendanceSummaryService (sumber yang sama dengan Daftar Upah); hari aktif dari
 * baris Daftar Upah. Satu bulan dibaca sekali per permintaan. Daftarkan sebagai scoped binding.
 */
class AttendanceMonths
{
    /** @var array<string, array<string, array<string, mixed>>> [bulan => [id karyawan => ringkasan]] */
    private array $summaries = [];

    public function __construct(private PayrollMonths $payroll, private AttendanceSummaryService $summaryService) {}

    /**
     * Item bulan itu: baris Daftar Upah + ringkasan absensi & lembur karyawannya.
     *
     * @return list<array{month: string, row: array<string, mixed>, facts: EmployeeFacts, attendance: array<string, mixed>}>
     */
    public function month(string $month, DashboardFilters $filters): array
    {
        $items = $this->payroll->month($month, $filters);
        if (! isset($this->summaries[$month])) {
            $all = $this->payroll->month($month, DashboardFilters::none());
            $this->summaries[$month] = $this->summaryService->forPeriod($month, array_map(fn (array $item) => $item['row']['employee_id'], $all));
        }

        return array_map(fn (array $item) => [...$item, 'attendance' => $this->summaries[$month][$item['row']['employee_id']]], $items);
    }

    /** @return list<array{month: string, row: array<string, mixed>, facts: EmployeeFacts, attendance: array<string, mixed>}> */
    public function range(DashboardPeriod $period, DashboardFilters $filters): array
    {
        return array_merge(...array_map(fn (string $month) => $this->month($month, $filters), $period->months()) ?: [[]]);
    }
}
