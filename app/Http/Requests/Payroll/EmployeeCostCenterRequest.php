<?php

namespace App\Http\Requests\Payroll;

use App\Services\Payroll\WageSchemeRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Tambah catatan riwayat cost center: { cost_center_id, effective_date, notes }.
 */
class EmployeeCostCenterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'cost_center_id' => ['required', 'uuid', 'exists:cost_centers,id'],
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
        return [
            'cost_center_id.required' => 'Cost Center wajib dipilih',
            'cost_center_id.uuid' => 'Cost Center tidak ditemukan',
            'cost_center_id.exists' => 'Cost Center tidak ditemukan',
            ...WageSchemeRules::effectiveDateMessages(),
        ];
    }
}
