<?php

namespace App\Http\Requests\Attendance;

use App\Enums\OvertimeType;
use App\Support\DataPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tambah / ubah / hapus SATU baris lembur oleh admin (hasilnya bersumber "manual"):
 *
 *   POST   { period, employee_id, overtime_type, date, start_time, end_time, description, reason }
 *   PUT    { date, start_time, end_time, description, reason }
 *   DELETE { reason }
 *
 * Alasan wajib (min. 5 karakter). Jam selesai lebih kecil dari jam mulai = lewat tengah malam.
 */
class OvertimeEntryRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            /** Alasan perubahan (min. 5 karakter). */
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];

        if ($this->isMethod('DELETE')) {
            return $rules;
        }

        $rules += [
            /** @example 2026-10-05 */
            'date' => ['required', 'date_format:Y-m-d'],
            /** @example 17:00 */
            'start_time' => ['required', 'date_format:H:i'],
            /** @example 19:30 */
            'end_time' => ['required', 'date_format:H:i'],
            'description' => ['required', 'string', 'max:500'],
        ];

        if ($this->isMethod('POST')) {
            $rules += [
                'period' => ['required', ...DataPeriod::rules()],
                'employee_id' => ['required', 'uuid', 'exists:employees,id'],
                /** reguler / dw */
                'overtime_type' => ['required', Rule::enum(OvertimeType::class)],
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan wajib diisi',
            'reason.min' => 'Alasan minimal 5 karakter',
            'date.required' => 'Tanggal wajib diisi',
            'date.date_format' => 'Tanggal harus berformat YYYY-MM-DD',
            'start_time.required' => 'Jam mulai wajib diisi',
            'start_time.date_format' => 'Jam mulai harus berformat HH:MM',
            'end_time.required' => 'Jam selesai wajib diisi',
            'end_time.date_format' => 'Jam selesai harus berformat HH:MM',
            'description.required' => 'Uraian Pekerjaan wajib diisi',
            'period.required' => 'Periode wajib dipilih',
            ...DataPeriod::messages(),
            'employee_id.required' => 'Karyawan wajib dipilih',
            'employee_id.exists' => 'Karyawan tidak ditemukan',
            'overtime_type.required' => 'Jenis lembur wajib dipilih',
            'overtime_type.enum' => 'Jenis lembur harus reguler atau dw',
        ];
    }

    /** Tanggal tidak boleh melewati bulan berjalan. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $date = (string) $this->input('date');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && substr($date, 0, 7) > DataPeriod::today()->format('Y-m')) {
                    $validator->errors()->add('date', 'Tanggal tidak boleh melewati bulan berjalan');
                }
            },
        ];
    }
}
