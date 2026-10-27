<?php

namespace App\Http\Requests\Payroll;

use App\Models\Employee;
use App\Services\Payroll\WageSchemeRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Tambah catatan riwayat skema upah (HANYA Karyawan Casual & Daily Worker).
 * Isian & aturannya ada di App\Services\Payroll\WageSchemeRules, ditambah { effective_date, notes }.
 */
class EmployeeWageSchemeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            ...WageSchemeRules::rules($this->all()),
            /**
             * Berlaku mulai, format YYYY-MM-DD.
             *
             * @example 2026-10-01
             */
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [...WageSchemeRules::messages(), ...WageSchemeRules::effectiveDateMessages()];
    }

    /** Karyawan Tetap & PKWT tidak memakai skema upah. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var Employee $employee */
                $employee = $this->route('employee');

                if (! $employee->hasDailyWage()) {
                    $validator->errors()->add('employee_id', WageSchemeRules::NOT_DAILY_WAGE_MESSAGE);
                }
            },
        ];
    }
}
