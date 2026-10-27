<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceCode;
use App\Models\Employee;
use App\Support\DataPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * KOREKSI MANUAL satu tanggal absensi: { date, status_code (null = kosongkan), reason (wajib) }.
 * Hanya karyawan Casual & Daily Worker; tanggal paling lambat bulan berjalan.
 */
class EmployeeAttendanceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /**
             * Tanggal, format YYYY-MM-DD.
             *
             * @example 2026-10-05
             */
            'date' => ['required', 'date_format:Y-m-d'],
            /** Kode baru; null = kosongkan (tidak masuk). */
            'status_code' => ['present', 'nullable', Rule::enum(AttendanceCode::class)],
            /** Alasan koreksi (wajib). */
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required' => 'Tanggal wajib diisi',
            'date.date_format' => 'Tanggal harus berformat YYYY-MM-DD',
            'status_code.present' => 'Kode wajib dikirim (null untuk mengosongkan)',
            'status_code.enum' => 'Kode harus K, DW, I, S, M, C, atau L',
            'reason.required' => 'Alasan koreksi wajib diisi',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var Employee $employee */
                $employee = $this->route('employee');
                if (! $employee->hasDailyWage()) {
                    $validator->errors()->add('employee_id', 'Koreksi absensi hanya untuk karyawan Casual & Daily Worker');
                }

                $date = (string) $this->input('date');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && substr($date, 0, 7) > DataPeriod::today()->format('Y-m')) {
                    $validator->errors()->add('date', 'Tanggal tidak boleh melewati bulan berjalan');
                }
            },
        ];
    }
}
