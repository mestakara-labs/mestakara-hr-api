<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Validation\Rule;

/**
 * Ekspor Daftar Upah: ?period&group_by&group_key&format=xlsx|pdf.
 * group_key kosong = semua kelompok (+ sheet REKAP & PAYROLL); "tanpa" = Tanpa Kelompok / Tanpa Bidang.
 */
class PayrollExportRequest extends PayrollRequest
{
    public const FORMATS = ['xlsx', 'pdf'];

    public function rules(): array
    {
        return [
            ...parent::rules(),
            /** Kunci kelompok dari GET /payroll (groups[].key). Kosong = semua kelompok. */
            'group_key' => ['nullable', 'string', 'max:40'],
            /** xlsx (bawaan) atau pdf. */
            'format' => ['nullable', Rule::in(self::FORMATS)],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'format.in' => 'Format harus xlsx atau pdf',
        ];
    }

    public function fileFormat(): string
    {
        return $this->validated('format') ?? 'xlsx';
    }

    public function groupKey(): ?string
    {
        return $this->validated('group_key');
    }
}
