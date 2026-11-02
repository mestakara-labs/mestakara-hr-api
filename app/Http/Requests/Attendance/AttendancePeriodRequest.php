<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceImportType;
use App\Enums\EmployeeCategory;
use App\Services\Attendance\AttendancePeriod;
use App\Support\DataPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Periode absensi ?period=YYYY-MM (wajib, maksimal bulan berjalan; aturan sama dengan Periode Data),
 * dan — kalau endpoint-nya memerlukan — jenis unggahan ?type=daftar_hadir|lembur_reguler|lembur_dw serta kategori
 * karyawan ?employee_category=casual|daily_worker (salah / kosong = 422; Lembur DW + daily_worker = 422).
 * Dipakai oleh semua endpoint baca Unggah Absensi.
 */
class AttendancePeriodRequest extends FormRequest
{
    /** Jenis yang boleh dipilih; diganti oleh subclass. null = endpoint tanpa ?type. */
    protected ?array $types = null;

    /** Endpoint ini wajib ?employee_category (lihat App\Enums\EmployeeCategory)? */
    protected bool $requiresCategory = false;

    public function rules(): array
    {
        $rules = [
            /**
             * Periode "YYYY-MM".
             *
             * @example 2026-10
             */
            'period' => ['required', ...DataPeriod::rules()],
        ];

        if ($this->types !== null) {
            $rules['type'] = ['required', Rule::in(array_map(fn (AttendanceImportType $type) => $type->value, $this->types))];
        }
        if ($this->requiresCategory) {
            /** Kategori karyawan: casual atau daily_worker. */
            $rules['employee_category'] = ['required', Rule::enum(EmployeeCategory::class)];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->requiresCategory && $this->types !== null && $validator->errors()->isEmpty()
                && $this->input('type') === AttendanceImportType::DailyWorkerOvertime->value
                && ! $this->employeeCategory()->allowsDw()) {
                $validator->errors()->add('employee_category', EmployeeCategory::DW_ONLY_CASUAL_MESSAGE);
            }
        }];
    }

    public function messages(): array
    {
        return [
            'period.required' => 'Periode wajib dipilih',
            ...DataPeriod::messages(),
            'type.required' => 'Jenis wajib dipilih',
            'type.in' => 'Jenis harus salah satu dari: '.implode(', ', array_map(fn (AttendanceImportType $type) => $type->value, $this->types ?? [])),
            'employee_category.required' => EmployeeCategory::INVALID_MESSAGE,
            'employee_category.enum' => EmployeeCategory::INVALID_MESSAGE,
        ];
    }

    public function period(): AttendancePeriod
    {
        return new AttendancePeriod($this->validated('period'));
    }

    public function importType(): AttendanceImportType
    {
        return AttendanceImportType::from($this->validated('type'));
    }

    public function employeeCategory(): EmployeeCategory
    {
        return EmployeeCategory::from((string) $this->input('employee_category'));
    }
}
