<?php

namespace App\Services\Payroll;

use App\Models\PayrollDeduction;
use App\Models\PayrollDeductionChange;
use App\Services\Attendance\AttendancePeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POTONGAN manual daftar upah (BPJS TK/KES, PPIP/PPh 21, Kebun/Unit).
 *
 *   update()        ubah satu / banyak karyawan; hanya isian yang dikirim yang berubah
 *   copyPrevious()  salin potongan periode sebelumnya untuk karyawan yang potongannya masih 0 semua
 *                   (dan potongan periode sebelumnya > 0); save=false hanya pratinjau
 *
 * Ditolak (422): periode dikunci, karyawan tidak ada di daftar upah periode itu, potongan > jumlah upah.
 * Setiap perubahan nilai dicatat di payroll_deduction_changes.
 */
class PayrollDeductionService
{
    private const SOURCE_EDIT = 'ubah';

    private const SOURCE_COPY = 'salin';

    public function __construct(private PayrollCalculator $calculator) {}

    /**
     * @param  list<string>  $employeeIds
     * @param  array<string, int>  $values  isian yang dikirim saja, mis. ['bpjs' => 50000]
     * @return int jumlah karyawan yang disimpan
     */
    public function update(AttendancePeriod $period, array $employeeIds, array $values): int
    {
        PayrollLockService::ensureNotLocked($period);
        $rows = $this->calculator->rows($period);
        $current = $this->deductions($period->period, $employeeIds);
        $errors = [];

        foreach (array_values($employeeIds) as $index => $employeeId) {
            $row = $rows[$employeeId] ?? null;
            if ($row === null) {
                $errors["employee_ids.{$index}"] = 'Karyawan tidak ada di daftar upah '.$period->label();

                continue;
            }

            $new = [...$this->valuesOf($current->get($employeeId)), ...$values];
            $total = array_sum($new);
            if ($total > $row['gross']) {
                $errors["employee_ids.{$index}"] = "{$row['name']}: potongan Rp".self::rupiah($total).' melebihi jumlah upah Rp'.self::rupiah($row['gross']);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($period, $employeeIds, $values, $current) {
            foreach ($employeeIds as $employeeId) {
                $this->save($period->period, $employeeId, $current->get($employeeId), $values, self::SOURCE_EDIT);
            }
        });

        return count($employeeIds);
    }

    /**
     * @return array{source_period: string, employees: list<array<string, mixed>>, totals: array{bpjs: int, pph21: int, unit: int}}
     */
    public function copyPrevious(AttendancePeriod $period, bool $save): array
    {
        PayrollLockService::ensureNotLocked($period);
        $sourcePeriod = Carbon::parse($period->period.'-01')->subMonth()->format('Y-m');
        $rows = $this->calculator->rows($period);
        $current = $this->deductions($period->period, array_keys($rows));
        $previous = $this->deductions($sourcePeriod, array_keys($rows));

        $employees = [];
        foreach ($rows as $employeeId => $row) {
            $source = $previous->get($employeeId);
            $isStillZero = array_sum($this->valuesOf($current->get($employeeId))) === 0;

            if ($isStillZero && $source !== null && $source->total() > 0) {
                $employees[] = [
                    'employee_id' => $employeeId,
                    'registration_number' => $row['registration_number'],
                    'name' => $row['name'],
                    ...$this->valuesOf($source),
                ];
            }
        }

        if ($save) {
            DB::transaction(function () use ($period, $employees, $current) {
                foreach ($employees as $employee) {
                    $values = array_intersect_key($employee, array_flip(PayrollDeduction::FIELDS));
                    $this->save($period->period, $employee['employee_id'], $current->get($employee['employee_id']), $values, self::SOURCE_COPY);
                }
            });
        }

        return [
            'source_period' => $sourcePeriod,
            'employees' => $employees,
            'totals' => [
                'bpjs' => array_sum(array_column($employees, 'bpjs')),
                'pph21' => array_sum(array_column($employees, 'pph21')),
                'unit' => array_sum(array_column($employees, 'unit')),
            ],
        ];
    }

    /** Simpan potongan satu karyawan + catat setiap isian yang berubah. */
    private function save(string $period, string $employeeId, ?PayrollDeduction $deduction, array $values, string $source): void
    {
        $old = $this->valuesOf($deduction);
        $deduction ??= new PayrollDeduction(['period' => $period, 'employee_id' => $employeeId]);
        $deduction->fill([...$values, 'updated_by' => auth()->id()])->save();

        foreach ($values as $field => $value) {
            if ($old[$field] !== (int) $value) {
                PayrollDeductionChange::query()->create([
                    'period' => $period,
                    'employee_id' => $employeeId,
                    'field' => $field,
                    'old_value' => $old[$field],
                    'new_value' => (int) $value,
                    'source' => $source,
                    'changed_by' => auth()->id(),
                ]);
            }
        }
    }

    /** @return Collection<string, PayrollDeduction> */
    private function deductions(string $period, array $employeeIds): Collection
    {
        return PayrollDeduction::query()->where('period', $period)->whereIn('employee_id', $employeeIds)->get()->keyBy('employee_id');
    }

    /** @return array{bpjs: int, pph21: int, unit: int} */
    private function valuesOf(?PayrollDeduction $deduction): array
    {
        return [
            'bpjs' => $deduction?->bpjs ?? 0,
            'pph21' => $deduction?->pph21 ?? 0,
            'unit' => $deduction?->unit ?? 0,
        ];
    }

    private static function rupiah(int $amount): string
    {
        return number_format($amount, 0, ',', '.');
    }
}
