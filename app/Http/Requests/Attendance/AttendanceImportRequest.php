<?php

namespace App\Http\Requests\Attendance;

/**
 * Pratinjau / simpan unggahan (multipart/form-data): { file, period, type }.
 */
class AttendanceImportRequest extends AttendanceTemplateRequest
{
    public const MAX_SIZE_MB = 10;

    public function rules(): array
    {
        return [
            ...parent::rules(),
            /** File .xlsx dari template unggah absensi, maks. 10 MB. */
            'file' => ['required', 'file', 'extensions:xlsx', 'max:'.(self::MAX_SIZE_MB * 1024)],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'file.required' => 'File Excel wajib diunggah',
            'file.file' => 'File Excel wajib diunggah',
            'file.extensions' => 'Format file harus XLSX (template unggah absensi)',
            'file.max' => 'Ukuran file maksimal '.self::MAX_SIZE_MB.' MB',
        ];
    }
}
