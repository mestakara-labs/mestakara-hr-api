<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Isian form Cost Center (tambah & ubah): { code, name, description }.
 */
class CostCenterRequest extends FormRequest
{
    public function rules(): array
    {
        $currentId = $this->route('cost_center')?->id;

        return [
            /**
             * Kode cost center SAP, unik.
             *
             * @example 4A44AGR007
             */
            'code' => ['required', 'string', 'max:20', Rule::unique('cost_centers')->ignore($currentId)],
            /**
             * Nama / deskripsi singkat.
             *
             * @example Tiket & Parkir
             */
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Kode Cost Center wajib diisi',
            'code.max' => 'Kode Cost Center maksimal 20 karakter',
            'code.unique' => 'Kode Cost Center "'.$this->input('code').'" sudah ada',
            'name.required' => 'Nama Cost Center wajib diisi',
        ];
    }
}
