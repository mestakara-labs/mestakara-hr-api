<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Isian form Kelompok Daftar Upah (tambah & ubah): { name, sort_order, description }.
 */
class PayrollGroupRequest extends FormRequest
{
    public function rules(): array
    {
        $currentId = $this->route('payroll_group')?->id;

        return [
            /**
             * Nama kelompok, unik.
             *
             * @example Gunung Mas
             */
            'name' => ['required', 'string', 'max:100', Rule::unique('payroll_groups')->ignore($currentId)],
            /** Urutan di daftar upah (kosong = 0). */
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'description' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama Kelompok wajib diisi',
            'name.unique' => 'Kelompok "'.$this->input('name').'" sudah ada',
            'sort_order.integer' => 'Urutan harus berupa angka bulat',
            'sort_order.min' => 'Urutan tidak boleh negatif',
        ];
    }
}
