<?php

namespace App\Http\Requests\Payroll;

use App\Enums\WageType;
use App\Models\EmployeeWageScheme;
use App\Services\Payroll\WageSchemeRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ubah skema upah BANYAK karyawan sekaligus:
 *
 *   { employee_ids: [...], effective_date: "2026-10-01", fields: { base_salary: 2500000 }, notes: "..." }
 *
 * `fields` hanya berisi isian yang DIUBAH; isian lain disalin dari skema yang berlaku
 * (lihat WageSchemeService::bulk). `payroll_group_id: null` = kosongkan kelompok.
 */
class WageSchemeBulkRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['required', 'uuid', 'distinct', 'exists:employees,id'],
            /**
             * Berlaku mulai, format YYYY-MM-DD.
             *
             * @example 2026-10-01
             */
            'effective_date' => ['required', 'date_format:Y-m-d'],
            /** Isian skema yang diubah (minimal satu). */
            'fields' => ['required', 'array:'.implode(',', EmployeeWageScheme::SCHEME_FIELDS)],
            'fields.wage_type' => ['sometimes', 'required', Rule::enum(WageType::class)],
            'fields.base_salary' => ['sometimes', 'required', 'integer', 'min:1'],
            'fields.working_days' => ['sometimes', 'required', 'integer', 'between:1,31'],
            'fields.is_capped' => ['sometimes', 'required', 'boolean'],
            'fields.cap_days' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
            'fields.operational_position_allowance' => ['sometimes', 'required', 'integer', 'min:0'],
            'fields.incentive' => ['sometimes', 'required', 'integer', 'min:0'],
            'fields.has_meal_allowance' => ['sometimes', 'required', 'boolean'],
            'fields.meal_allowance_per_day' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'fields.payroll_group_id' => ['sometimes', 'nullable', 'uuid', 'exists:payroll_groups,id'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        $fieldMessages = [];
        foreach (WageSchemeRules::messages() as $key => $message) {
            $fieldMessages["fields.{$key}"] = $message;
        }

        return [
            'employee_ids.required' => 'Pilih minimal satu karyawan',
            'employee_ids.*.distinct' => 'Karyawan dipilih lebih dari sekali',
            'employee_ids.*.exists' => 'Karyawan tidak ditemukan',
            'employee_ids.*.uuid' => 'Karyawan tidak ditemukan',
            'fields.required' => 'Pilih minimal satu isian yang diubah',
            'fields.array' => 'Isian yang diubah tidak dikenal',
            ...$fieldMessages,
            ...WageSchemeRules::effectiveDateMessages(),
        ];
    }
}
