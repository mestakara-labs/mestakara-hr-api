<?php

namespace App\Http\Requests\Payroll;

use App\Enums\SignatorySource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ubah susunan penanda tangan (Umum / satu Kelompok Daftar Upah / satu Bidang):
 *
 *   {
 *     place: "Gunung Mas",
 *     payroll_sections: [ { label: "Diperiksa Oleh", signers: [ { source: "karyawan", employee_id: "..." },
 *                                                              { source: "manual", name: "...", position: "..." } ] } ],
 *     spkl_sections: [ ... ]
 *   }
 *
 * Umum: tempat & kedua susunan WAJIB (minimal 1 bagian).
 * Kelompok / Bidang: tempat & susunan boleh null (= pakai Umum); kalau diisi minimal 1 bagian.
 */
class SignatureSettingRequest extends FormRequest
{
    /** Susunan Umum? (route PUT /signature-settings/general) */
    private function isGeneral(): bool
    {
        return $this->route('payroll_group') === null && $this->route('division') === null;
    }

    public function rules(): array
    {
        $required = $this->isGeneral() ? 'required' : 'nullable';

        return [
            /**
             * Tempat penandatanganan (Kelompok/Bidang: null = pakai tempat Umum).
             *
             * @example Gunung Mas
             */
            'place' => [$required, 'string', 'max:100'],
            ...$this->sectionRules('payroll_sections', $required),
            ...$this->sectionRules('spkl_sections', $required),
        ];
    }

    public function messages(): array
    {
        return [
            'place.required' => 'Tempat penandatanganan wajib diisi',
            'place.max' => 'Tempat penandatanganan maksimal 100 karakter',
            ...$this->sectionMessages('payroll_sections', 'Daftar Upah'),
            ...$this->sectionMessages('spkl_sections', 'SPKL'),
        ];
    }

    /** Aturan satu susunan: minimal 1 bagian, setiap bagian berlabel & minimal 1 orang. */
    private function sectionRules(string $field, string $required): array
    {
        $isEmployee = "required_if:{$field}.*.signers.*.source,".SignatorySource::Employee->value;
        $isManual = "required_if:{$field}.*.signers.*.source,".SignatorySource::Manual->value;

        return [
            $field => [$required, 'array', 'min:1'],
            "{$field}.*.label" => ['required', 'string', 'max:100'],
            "{$field}.*.signers" => ['required', 'array', 'min:1'],
            "{$field}.*.signers.*.source" => ['required', Rule::in(SignatorySource::values())],
            "{$field}.*.signers.*.employee_id" => [$isEmployee, 'nullable', 'uuid', 'exists:employees,id'],
            "{$field}.*.signers.*.name" => [$isManual, 'nullable', 'string', 'max:150'],
            "{$field}.*.signers.*.position" => [$isManual, 'nullable', 'string', 'max:150'],
        ];
    }

    /** Pesan satu susunan. */
    private function sectionMessages(string $field, string $document): array
    {
        $countMessage = "Penanda tangan {$document} minimal 1 bagian";
        $signerCountMessage = "Setiap bagian penanda tangan {$document} minimal berisi 1 orang";

        return [
            "{$field}.required" => $countMessage,
            "{$field}.array" => $countMessage,
            "{$field}.min" => $countMessage,
            "{$field}.*.label.required" => "Label bagian penanda tangan {$document} wajib diisi",
            "{$field}.*.label.max" => "Label bagian penanda tangan {$document} maksimal 100 karakter",
            "{$field}.*.signers.required" => $signerCountMessage,
            "{$field}.*.signers.array" => $signerCountMessage,
            "{$field}.*.signers.min" => $signerCountMessage,
            "{$field}.*.signers.*.source.*" => 'Asal penanda tangan harus "karyawan" atau "manual"',
            "{$field}.*.signers.*.employee_id.required_if" => "Karyawan penanda tangan {$document} wajib dipilih",
            "{$field}.*.signers.*.employee_id.uuid" => "Karyawan penanda tangan {$document} tidak ditemukan",
            "{$field}.*.signers.*.employee_id.exists" => "Karyawan penanda tangan {$document} tidak ditemukan",
            "{$field}.*.signers.*.name.required_if" => "Nama penanda tangan {$document} wajib diisi",
            "{$field}.*.signers.*.name.max" => "Nama penanda tangan {$document} maksimal 150 karakter",
            "{$field}.*.signers.*.position.required_if" => "Jabatan penanda tangan {$document} wajib diisi",
            "{$field}.*.signers.*.position.max" => "Jabatan penanda tangan {$document} maksimal 150 karakter",
        ];
    }
}
