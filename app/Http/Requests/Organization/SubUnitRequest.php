<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Isian form Sub Unit (tambah & ubah): { name, afdeling_code, description }.
 * Kode afdeling opsional, disimpan huruf besar (" afd03 " -> "AFD03"), unik bila diisi.
 */
class SubUnitRequest extends FormRequest
{
    /** Rapikan kode afdeling sebelum diperiksa: jadikan huruf besar. ("" otomatis jadi null oleh Laravel.) */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('afdeling_code'))) {
            $this->merge(['afdeling_code' => mb_strtoupper($this->input('afdeling_code'))]);
        }
    }

    public function rules(): array
    {
        $currentId = $this->route('sub_unit')?->id;

        return [
            /**
             * Nama sub unit, unik.
             *
             * @example Agrowisata Gunung Mas
             */
            'name' => ['required', 'string', 'max:150', Rule::unique('sub_units')->ignore($currentId)],
            /**
             * Kode afdeling (opsional, tanpa spasi). Kirim "" untuk mengosongkan.
             *
             * @example AFD03
             */
            'afdeling_code' => ['nullable', 'string', 'max:20', 'regex:/^\S+$/', Rule::unique('sub_units')->ignore($currentId)],
            'description' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama Sub Unit wajib diisi',
            'name.unique' => 'Sub unit "'.$this->input('name').'" sudah ada',
            'afdeling_code.max' => 'Kode Afdeling maksimal 20 karakter',
            'afdeling_code.regex' => 'Kode Afdeling tidak boleh berisi spasi',
            'afdeling_code.unique' => 'Kode Afdeling "'.$this->input('afdeling_code').'" sudah dipakai sub unit lain',
        ];
    }
}
