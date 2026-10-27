<?php

namespace App\Http\Requests\Payroll;

use App\Services\Payroll\WageSchemeRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Tambah catatan riwayat rekening bank: { bank_name, account_number, account_holder, effective_date, notes }.
 */
class EmployeeBankAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** @example BRI */
            'bank_name' => ['required', 'string', 'max:100'],
            /**
             * Nomor rekening, angka saja.
             *
             * @example 012301000123567
             */
            'account_number' => ['required', 'string', 'max:30', 'regex:/^\d+$/'],
            /** @example ASEP SUNANDAR */
            'account_holder' => ['required', 'string', 'max:150'],
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
            'bank_name.required' => 'Nama Bank wajib diisi',
            'account_number.required' => 'No. Rekening wajib diisi',
            'account_number.regex' => 'No. Rekening hanya boleh berisi angka',
            'account_number.max' => 'No. Rekening maksimal 30 digit',
            'account_holder.required' => 'Atas Nama wajib diisi',
            ...WageSchemeRules::effectiveDateMessages(),
        ];
    }
}
