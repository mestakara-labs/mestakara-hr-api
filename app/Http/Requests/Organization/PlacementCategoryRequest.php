<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Isian form Kategori Objek (tambah & ubah): { name, description }.
 */
class PlacementCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        $currentId = $this->route('placement_category')?->id;

        return [
            /**
             * Nama kategori, unik.
             *
             * @example Penginapan
             */
            'name' => ['required', 'string', 'max:100', Rule::unique('placement_categories')->ignore($currentId)],
            'description' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama Kategori wajib diisi',
            'name.unique' => 'Kategori "'.$this->input('name').'" sudah ada',
        ];
    }
}
