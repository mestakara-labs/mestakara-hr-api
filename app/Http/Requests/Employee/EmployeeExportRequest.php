<?php

namespace App\Http\Requests\Employee;

use App\Enums\EmployeeActiveStatus;
use App\Support\DataPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filter ekspor Data Karyawan:
 *   ?active_status=Aktif | Belum Aktif | Nonaktif   (kosong = semua karyawan)
 *   ?period=YYYY-MM                                 (kosong = per hari ini; lihat App\Support\DataPeriod)
 */
class EmployeeExportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /**
             * Status kepegawaian. Kosongkan untuk semua karyawan.
             *
             * @example Aktif
             */
            'active_status' => ['nullable', Rule::in(EmployeeActiveStatus::values())],
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
        return [
            'active_status.in' => 'Status Kepegawaian harus Aktif, Belum Aktif, atau Nonaktif',
            ...DataPeriod::messages(),
        ];
    }
}
