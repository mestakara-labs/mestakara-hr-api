<?php

namespace App\Http\Requests\Payroll;

use App\Enums\PayrollGroupBy;
use App\Http\Requests\Attendance\AttendancePeriodRequest;
use Illuminate\Validation\Rule;

/**
 * Daftar Upah: ?period=YYYY-MM (wajib, maksimal bulan berjalan) & ?group_by=payroll_group|division (bawaan payroll_group).
 */
class PayrollRequest extends AttendancePeriodRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            /** Pengelompokan: payroll_group (bawaan) atau division. */
            'group_by' => ['nullable', Rule::enum(PayrollGroupBy::class)],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'group_by.*' => 'Pengelompokan harus payroll_group atau division',
        ];
    }

    public function groupBy(): PayrollGroupBy
    {
        return PayrollGroupBy::tryFrom((string) $this->validated('group_by')) ?? PayrollGroupBy::PayrollGroup;
    }
}
