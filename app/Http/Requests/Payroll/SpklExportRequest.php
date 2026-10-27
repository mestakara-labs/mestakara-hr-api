<?php

namespace App\Http\Requests\Payroll;

use App\Enums\OvertimeType;
use Illuminate\Validation\Rule;

/**
 * Ekspor SPKL: ?period&type=reguler|dw&group_by&group_key&format&employee_ids=a,b.
 * Tanpa employee_ids = semua karyawan yang punya lembur jenis itu.
 */
class SpklExportRequest extends PayrollExportRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('employee_ids'))) {
            $this->merge(['employee_ids' => array_values(array_filter(array_map('trim', explode(',', $this->input('employee_ids')))))]);
        }
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            /** Jenis lembur: reguler atau dw. */
            'type' => ['required', Rule::enum(OvertimeType::class)],
            /** Id karyawan dipisah koma, mis. "id1,id2". */
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'type.required' => 'Jenis lembur wajib dipilih',
            'type.*' => 'Jenis lembur harus reguler atau dw',
            'employee_ids.*.uuid' => 'Karyawan tidak ditemukan',
        ];
    }

    public function overtimeType(): OvertimeType
    {
        return OvertimeType::from($this->validated('type'));
    }

    /** @return list<string>|null */
    public function employeeIds(): ?array
    {
        $ids = $this->validated('employee_ids');

        return $ids === null || $ids === [] ? null : array_values($ids);
    }
}
