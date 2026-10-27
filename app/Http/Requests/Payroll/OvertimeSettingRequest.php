<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ubah Pengaturan Lembur. Penanda tangan SPKL & Daftar Upah diatur di SignatureSettingRequest.
 */
class OvertimeSettingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** Pembagi upah sejam, mis. 173. */
            'hourly_divisor' => ['required', 'integer', 'min:1', 'max:65535'],
            /** Pengali jam lembur pertama, mis. 1.5. */
            'first_hour_multiplier' => ['required', 'numeric', 'min:1', 'max:99'],
            /** Pengali jam lembur berikutnya, mis. 2. */
            'next_hour_multiplier' => ['required', 'numeric', 'min:1', 'max:99'],
            'max_hours_per_day' => ['required', 'integer', 'between:1,24'],
            'max_hours_per_week' => ['required', 'integer', 'between:1,168'],
            /** Pembulatan rupiah, mis. 100. */
            'rounding' => ['required', 'integer', 'min:1'],
            /**
             * Jam mulai bawaan Lembur DW, format HH:MM.
             *
             * @example 17:00
             */
            'dw_default_start_time' => ['required', 'date_format:H:i'],
            /** Uraian pekerjaan bawaan Lembur DW (wajib, tidak ada teks bawaan). */
            'dw_default_description' => ['required', 'string', 'max:255'],
            /** Kelipatan jam lembur dalam menit, mis. 30. */
            'time_step_minutes' => ['required', 'integer', 'between:1,60'],
        ];
    }

    public function messages(): array
    {
        return [
            'hourly_divisor.*' => 'Pembagi Upah Sejam harus berupa angka bulat lebih dari 0',
            'first_hour_multiplier.*' => 'Pengali Jam Pertama harus berupa angka minimal 1',
            'next_hour_multiplier.*' => 'Pengali Jam Berikutnya harus berupa angka minimal 1',
            'max_hours_per_day.*' => 'Maks. Jam per Hari harus 1–24',
            'max_hours_per_week.*' => 'Maks. Jam per Minggu harus 1–168',
            'rounding.*' => 'Pembulatan harus berupa angka bulat lebih dari 0',
            'dw_default_start_time.required' => 'Jam Mulai Lembur DW wajib diisi',
            'dw_default_start_time.date_format' => 'Jam Mulai Lembur DW harus berformat HH:MM, mis. 17:00',
            'dw_default_description.required' => 'Uraian Pekerjaan Lembur DW wajib diisi',
            'time_step_minutes.*' => 'Kelipatan Waktu harus 1–60 menit',
        ];
    }
}
