<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Isian form Bidang (tambah & ubah): { code, name, description }.
 */
class DivisionRequest extends FormRequest
{
    public function rules(): array
    {
        // Saat ubah, bidang itu sendiri tidak dianggap kembar.
        $currentId = $this->route('division')?->id;

        return [
            /**
             * Kode singkat, unik.
             *
             * @example ADK
             */
            'code' => ['required', 'string', 'max:10', Rule::unique('divisions')->ignore($currentId)],
            /**
             * Nama bidang, unik.
             *
             * @example Administrasi & Keuangan
             */
            'name' => ['required', 'string', 'max:150', Rule::unique('divisions')->ignore($currentId)],
            'description' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Kode wajib diisi',
            'code.unique' => 'Kode "'.$this->input('code').'" sudah dipakai bidang lain',
            'name.required' => 'Nama Bidang wajib diisi',
            'name.unique' => 'Bidang "'.$this->input('name').'" sudah ada',
        ];
    }
}
