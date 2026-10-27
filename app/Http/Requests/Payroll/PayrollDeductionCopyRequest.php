<?php

namespace App\Http\Requests\Payroll;

use App\Http\Requests\Attendance\AttendancePeriodRequest;

/**
 * Salin potongan periode sebelumnya: { period: "2026-10", save: false } (false = pratinjau saja).
 */
class PayrollDeductionCopyRequest extends AttendancePeriodRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            /** true = simpan; false = pratinjau, tidak menyimpan apa pun. */
            'save' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'save.*' => 'Isian save harus true atau false',
        ];
    }
}
