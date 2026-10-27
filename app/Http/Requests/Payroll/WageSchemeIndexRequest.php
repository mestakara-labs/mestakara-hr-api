<?php

namespace App\Http\Requests\Payroll;

use App\Support\DataPeriod;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filter daftar skema upah & template impor: ?period=YYYY-MM (kosong = per hari ini; lihat App\Support\DataPeriod).
 */
class WageSchemeIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /**
             * Periode Data "YYYY-MM". Kosongkan untuk data per hari ini.
             *
             * @example 2026-10
             */
            'period' => DataPeriod::rules(),
        ];
    }

    public function messages(): array
    {
        return DataPeriod::messages();
    }
}
