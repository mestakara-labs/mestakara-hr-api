<?php

namespace App\Http\Requests\Payroll;

use App\Services\Attendance\AttendancePeriod;
use App\Support\DataPeriod;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Kunci { period } / buka kunci { period, reason } periode upah.
 * Bulan berjalan / bulan depan tidak ditolak di sini, tetapi oleh PayrollLockService ("Periode belum berakhir").
 */
class PayrollLockRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            /** @example 2026-09 */
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ];

        if ($this->is('*/unlock')) {
            /** Alasan buka kunci, minimal 5 karakter. */
            $rules['reason'] = ['required', 'string', 'min:5', 'max:255'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'period.required' => 'Periode wajib dipilih',
            ...DataPeriod::messages(),
            'reason.required' => 'Alasan buka kunci wajib diisi',
            'reason.min' => 'Alasan buka kunci minimal 5 karakter',
            'reason.max' => 'Alasan buka kunci maksimal 255 karakter',
        ];
    }

    public function period(): AttendancePeriod
    {
        return new AttendancePeriod($this->validated('period'));
    }
}
