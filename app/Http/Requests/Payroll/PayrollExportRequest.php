<?php

namespace App\Http\Requests\Payroll;

use App\Enums\EmployeeCategory;
use App\Enums\PayrollDocument;
use App\Services\Payroll\PayrollExportOptions;
use App\Support\DataPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Ekspor Daftar Upah (SATU endpoint untuk semua dokumen):
 *
 *   ?period=YYYY-MM & group_by=payroll_group|division & employee_category=casual|daily_worker (wajib)
 *   & document=daftar_upah|payroll|spkl_all|spkl_reguler|spkl_dw (wajib)
 *   & group_keys=a,b (kunci kelompok dari GET /payroll, dipisah koma; kosong = semua kelompok)
 *   & format=xlsx|pdf & employee_ids=a,b (opsional, hanya SPKL)
 *   & sign_date=YYYY-MM-DD (opsional) tanggal tanda tangan di semua dokumen; tidak boleh sebelum tanggal 1 periode
 *   & show_draft=1|0 (opsional) tulisan DRAF & "(DRAF)" di nama file; periode dikunci tidak pernah DRAF
 *
 * spkl_all / spkl_dw untuk daily_worker = 422 (Lembur DW hanya untuk karyawan Casual).
 */
class PayrollExportRequest extends PayrollRequest
{
    public const FORMATS = ['xlsx', 'pdf'];

    public const INVALID_SIGN_DATE_MESSAGE = 'Tanggal tanda tangan tidak valid';

    /** "a,b" -> ["a", "b"] untuk group_keys & employee_ids. */
    protected function prepareForValidation(): void
    {
        foreach (['group_keys', 'employee_ids'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => array_values(array_filter(array_map('trim', explode(',', $this->input($field))), fn (string $value) => $value !== ''))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            /** Kategori karyawan: casual atau daily_worker. */
            'employee_category' => ['required', Rule::enum(EmployeeCategory::class)],
            /** Dokumen: daftar_upah, payroll, spkl_all, spkl_reguler, spkl_dw. */
            'document' => ['required', Rule::enum(PayrollDocument::class)],
            /** Kunci kelompok dari GET /payroll (groups[].key), dipisah koma. Kosong = semua kelompok. */
            'group_keys' => ['nullable', 'array'],
            'group_keys.*' => ['string', 'max:40'],
            /** xlsx (bawaan) atau pdf. */
            'format' => ['nullable', Rule::in(self::FORMATS)],
            /** Hanya SPKL: id karyawan dipisah koma, mis. "id1,id2". */
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['uuid'],
            /**
             * Tanggal tanda tangan, format YYYY-MM-DD; tidak boleh sebelum tanggal 1 periode. Kosong = tanggal kunci / hari ini.
             *
             * @example 2026-10-31
             */
            'sign_date' => ['nullable', 'date_format:Y-m-d'],
            /** 1 = tampilkan DRAF, 0 = tanpa DRAF. Kosong = DRAF selama periode belum dikunci. */
            'show_draft' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'employee_category.required' => EmployeeCategory::INVALID_MESSAGE,
            'employee_category.enum' => EmployeeCategory::INVALID_MESSAGE,
            'document.required' => 'Dokumen wajib dipilih',
            'document.enum' => 'Dokumen harus salah satu dari: '.implode(', ', PayrollDocument::values()),
            'format.in' => 'Format harus xlsx atau pdf',
            'employee_ids.*.uuid' => 'Karyawan tidak ditemukan',
            'sign_date.date_format' => self::INVALID_SIGN_DATE_MESSAGE,
            'show_draft.boolean' => 'Tampilkan DRAF harus 1 atau 0',
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->document()->usesDw() && ! $this->employeeCategory()->allowsDw()) {
                $validator->errors()->add('document', EmployeeCategory::DW_ONLY_CASUAL_MESSAGE);
            }
            if ($this->employeeIds() !== null && ! $this->document()->isSpkl()) {
                $validator->errors()->add('employee_ids', 'Pilihan karyawan hanya untuk dokumen SPKL');
            }
            // Dibandingkan sebagai teks tanggal (YYYY-MM-DD), supaya zona waktu tidak menggeser hari.
            $periodStart = $this->period()->start()->toDateString();
            if ($this->signDate() !== null && $this->signDate()->toDateString() < $periodStart) {
                $validator->errors()->add('sign_date', 'Tanggal tanda tangan tidak boleh sebelum 1 '.$this->period()->label());
            }
        }];
    }

    /** Pilihan cetak (sign_date & show_draft). */
    public function exportOptions(): PayrollExportOptions
    {
        $showDraft = $this->validated('show_draft');

        return new PayrollExportOptions($this->signDate(), $showDraft === null ? null : (bool) $showDraft);
    }

    /** sign_date sebagai tanggal WIB, null kalau kosong. */
    private function signDate(): ?Carbon
    {
        $date = $this->input('sign_date');

        return blank($date) ? null : Carbon::createFromFormat('!Y-m-d', $date, DataPeriod::TIMEZONE);
    }

    public function document(): PayrollDocument
    {
        return PayrollDocument::from((string) $this->input('document'));
    }

    public function fileFormat(): string
    {
        return $this->validated('format') ?? 'xlsx';
    }

    /** @return list<string>|null null = semua kelompok */
    public function groupKeys(): ?array
    {
        $keys = $this->validated('group_keys');

        return $keys === null || $keys === [] ? null : array_values(array_unique($keys));
    }

    /** @return list<string>|null */
    public function employeeIds(): ?array
    {
        $ids = $this->input('employee_ids');

        return ! is_array($ids) || $ids === [] ? null : array_values($ids);
    }
}
