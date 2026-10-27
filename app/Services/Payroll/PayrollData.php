<?php

namespace App\Services\Payroll;

use App\Models\PayrollSnapshotRow;
use App\Services\Attendance\AttendancePeriod;

/**
 * SUMBER ANGKA daftar upah satu periode:
 *
 *   periode DRAF     -> dihitung sekarang (PayrollCalculator) + penanda tangan dari pengaturan sekarang
 *   periode DIKUNCI  -> potret saat dikunci (payroll_snapshot_rows & payroll_snapshot_signers)
 *
 * Baris berbentuk datar (kolom payroll_snapshot_rows), urut nama, dikunci id karyawan.
 */
class PayrollData
{
    /** Kolom angka bulat di baris datar. */
    private const INTEGER_COLUMNS = [
        'hke', 'present_days', 'active_days', 'calendar_days', 'base_salary', 'daily_rate', 'base_pay',
        'regular_overtime_pay', 'dw_overtime_pay', 'overtime_pay', 'operational_allowance', 'incentive',
        'meal_allowance', 'gross', 'bpjs', 'pph21', 'unit', 'deductions_total', 'net',
    ];

    /** @param  array<string, array<string, mixed>>  $rows */
    public function __construct(
        public readonly AttendancePeriod $period,
        public readonly bool $locked,
        public readonly array $rows,
        public readonly SignatureBook $signatures,
    ) {}

    public static function load(AttendancePeriod $period): self
    {
        if ($period->isLocked()) {
            return new self($period, true, self::snapshotRows($period->period), SignatureBook::fromSnapshot($period->period));
        }

        return new self($period, false, app(PayrollCalculator::class)->rows($period), SignatureBook::live());
    }

    /** @return array<string, array<string, mixed>> */
    private static function snapshotRows(string $period): array
    {
        $rows = [];

        foreach (PayrollSnapshotRow::query()->where('period', $period)->orderBy('name')->get() as $snapshot) {
            $row = collect($snapshot->getAttributes())->except(['id', 'period', 'created_at'])->all();

            foreach (self::INTEGER_COLUMNS as $column) {
                $row[$column] = (int) $row[$column];
            }
            $row['cap_days'] = $row['cap_days'] === null ? null : (int) $row['cap_days'];
            $row['warnings'] = $row['warnings'] === null || $row['warnings'] === '' ? [] : explode("\n", $row['warnings']);

            $rows[$row['employee_id']] = $row;
        }

        return $rows;
    }

    /**
     * Baris datar untuk disimpan sebagai potret (peringatan digabung satu per baris).
     *
     * @return list<array<string, mixed>>
     */
    public function snapshotInserts(): array
    {
        return array_values(array_map(fn (array $row) => [
            ...$row,
            'period' => $this->period->period,
            'warnings' => implode("\n", $row['warnings']),
        ], $this->rows));
    }
}
