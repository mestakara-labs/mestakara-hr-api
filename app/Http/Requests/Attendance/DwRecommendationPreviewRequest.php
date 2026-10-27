<?php

namespace App\Http\Requests\Attendance;

/**
 * Pratinjau rekomendasi Lembur DW: { period, employee_ids? } (kosong = semua karyawan dengan hari DW > 0).
 */
class DwRecommendationPreviewRequest extends AttendancePeriodRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            /** Karyawan yang dihitung; kosongkan untuk semua karyawan dengan hari DW > 0. */
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['uuid', 'distinct', 'exists:employees,id'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'employee_ids.*.uuid' => 'Karyawan tidak ditemukan',
            'employee_ids.*.exists' => 'Karyawan tidak ditemukan',
            'employee_ids.*.distinct' => 'Karyawan dipilih lebih dari sekali',
        ];
    }
}
