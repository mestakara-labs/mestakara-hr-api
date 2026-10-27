<?php

namespace App\Http\Requests\Attendance;

/**
 * Simpan rekomendasi Lembur DW (persis hasil pratinjau):
 *
 *   { period, employees: [ { employee_id, entries: [ { date, start_time, end_time } ] } ] }
 *
 * `entries` boleh kosong = hanya menghapus Lembur DW Excel / rekomendasi lama karyawan itu.
 */
class DwRecommendationStoreRequest extends AttendancePeriodRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'employees' => ['required', 'array', 'min:1'],
            'employees.*.employee_id' => ['required', 'uuid', 'exists:employees,id'],
            'employees.*.entries' => ['present', 'array'],
            /** @example 2026-10-05 */
            'employees.*.entries.*.date' => ['required', 'date_format:Y-m-d'],
            /** @example 17:00 */
            'employees.*.entries.*.start_time' => ['required', 'date_format:H:i'],
            /** @example 18:30 */
            'employees.*.entries.*.end_time' => ['required', 'date_format:H:i'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'employees.required' => 'Pilih minimal satu karyawan',
            'employees.*.employee_id.required' => 'Karyawan wajib dipilih',
            'employees.*.employee_id.exists' => 'Karyawan tidak ditemukan',
            'employees.*.employee_id.uuid' => 'Karyawan tidak ditemukan',
            'employees.*.entries.present' => 'Daftar lembur wajib dikirim (boleh kosong)',
            'employees.*.entries.*.date.*' => 'Tanggal harus berformat YYYY-MM-DD',
            'employees.*.entries.*.start_time.*' => 'Jam mulai harus berformat HH:MM',
            'employees.*.entries.*.end_time.*' => 'Jam selesai harus berformat HH:MM',
        ];
    }
}
