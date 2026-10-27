<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceImportType;
use App\Services\Attendance\AttendancePeriod;
use App\Support\DataPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Periode absensi ?period=YYYY-MM (wajib, maksimal bulan berjalan; aturan sama dengan Periode Data),
 * dan — kalau endpoint-nya memerlukan — jenis unggahan ?type=daftar_hadir|lembur_reguler|lembur_dw.
 * Dipakai oleh semua endpoint baca Unggah Absensi.
 */
class AttendancePeriodRequest extends FormRequest
{
    /** Jenis yang boleh dipilih; diganti oleh subclass. null = endpoint tanpa ?type. */
    protected ?array $types = null;

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

        return $rules;
    }

    public function messages(): array
    {
        return [
            'period.required' => 'Periode wajib dipilih',
            ...DataPeriod::messages(),
            'type.required' => 'Jenis wajib dipilih',
            'type.in' => 'Jenis harus salah satu dari: '.implode(', ', array_map(fn (AttendanceImportType $type) => $type->value, $this->types ?? [])),
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
}
