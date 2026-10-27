<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Unggah file template skema upah yang sudah diisi (multipart/form-data): { file }.
 */
class WageSchemeImportRequest extends FormRequest
{
    public const MAX_SIZE_MB = 5;

    public function rules(): array
    {
        return [
            /** File .xlsx hasil GET /wage-schemes/template, maks. 5 MB. */
            'file' => ['required', 'file', 'extensions:xlsx', 'max:'.(self::MAX_SIZE_MB * 1024)],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'File Excel wajib diunggah',
            'file.file' => 'File Excel wajib diunggah',
            'file.extensions' => 'Format file harus XLSX (template skema upah)',
            'file.max' => 'Ukuran file maksimal '.self::MAX_SIZE_MB.' MB',
        ];
    }
}
