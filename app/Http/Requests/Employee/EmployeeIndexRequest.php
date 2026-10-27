<?php

namespace App\Http\Requests\Employee;

use App\Support\DataPeriod;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Daftar karyawan: ?period=YYYY-MM (opsional) = status, masa kerja, & jabatan dihitung per periode itu.
 */
class EmployeeIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /**
             * Periode Data "YYYY-MM". Kosongkan untuk data per hari ini.
             *
             * @example 2026-09
             */
            'period' => DataPeriod::rules(),
        ];
    }

    public function messages(): array
    {
        return DataPeriod::messages();
    }
}
