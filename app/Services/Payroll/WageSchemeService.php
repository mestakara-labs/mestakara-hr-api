<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeWageScheme;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Menyimpan catatan baru riwayat SKEMA UPAH (satu karyawan atau banyak sekaligus).
 * Catatan lama tidak pernah diubah; aturan isian ada di WageSchemeRules.
 */
class WageSchemeService
{
    /**
     * Tambah satu catatan skema dari isian yang sudah divalidasi (EmployeeWageSchemeRequest).
     *
     * @param  array<string, mixed>  $validated
     */
    public function create(Employee $employee, array $validated): EmployeeWageScheme
    {
        self::ensureNotLocked($validated['effective_date']);

        return $employee->wageSchemes()->create([
            ...WageSchemeRules::normalize($validated),
            'effective_date' => $validated['effective_date'],
            'notes' => $validated['notes'] ?? null,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * UBAH MASSAL: untuk tiap karyawan, salin skema yang berlaku pada $effectiveDate, timpa dengan $fields,
     * lalu simpan sebagai catatan baru. Semua tersimpan, atau tidak sama sekali kalau ada yang salah (422).
     *
     * Karyawan tanpa skema harus diberi isian lengkap (minimal Jenis Upah & Gaji Pokok).
     * Error per karyawan memakai nama "employees.<id karyawan>".
     *
     * @param  list<string>  $employeeIds
     * @param  array<string, mixed>  $fields  hanya isian yang diubah
     * @return int jumlah catatan baru
     */
    public function bulk(array $employeeIds, string $effectiveDate, array $fields, ?string $notes): int
    {
        self::ensureNotLocked($effectiveDate);
        $date = Carbon::parse($effectiveDate);
        $employees = Employee::query()->with('wageSchemes')->whereKey($employeeIds)->get();

        $errors = [];
        $schemes = [];

        foreach ($employees as $employee) {
            $key = "employees.{$employee->id}";

            if (! $employee->hasDailyWage()) {
                $errors[$key] = ["{$employee->name}: ".WageSchemeRules::NOT_DAILY_WAGE_MESSAGE];

                continue;
            }

            $merged = [
                ...($employee->wageSchemeOn($date)?->schemeValues() ?? []),
                ...Arr::only($fields, EmployeeWageScheme::SCHEME_FIELDS),
            ];
            // Uang makan 0 di skema lama = "tidak diberi", bukan nominal: kalau tidak dikirim, anggap kosong.
            if (! array_key_exists('meal_allowance_per_day', $fields) && ($merged['meal_allowance_per_day'] ?? 0) === 0) {
                $merged['meal_allowance_per_day'] = null;
            }

            $validator = Validator::make($merged, WageSchemeRules::rules($merged), WageSchemeRules::messages());
            if ($validator->fails()) {
                $errors[$key] = array_map(fn (string $message) => "{$employee->name}: {$message}", $validator->errors()->all());

                continue;
            }

            $schemes[$employee->id] = WageSchemeRules::normalize($merged);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($schemes, $effectiveDate, $notes) {
            foreach ($schemes as $employeeId => $scheme) {
                EmployeeWageScheme::create([
                    ...$scheme,
                    'employee_id' => $employeeId,
                    'effective_date' => $effectiveDate,
                    'notes' => $notes,
                    'created_by' => auth()->id(),
                ]);
            }
        });

        return count($schemes);
    }

    /** Tolak (422) skema yang Berlaku Mulai-nya ≤ akhir periode upah terkunci. */
    private static function ensureNotLocked(string $effectiveDate): void
    {
        $message = WageSchemeRules::lockedPeriodMessage($effectiveDate, WageSchemeRules::latestLockedPeriod());

        if ($message !== null) {
            throw ValidationException::withMessages(['effective_date' => $message]);
        }
    }
}
