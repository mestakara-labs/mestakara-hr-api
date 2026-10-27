<?php

namespace App\Http\Requests\Organization;

use App\Models\PlacementObject;
use App\Models\SubUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Isian form Objek Penempatan (tambah & ubah): { name, category_id, sub_unit_ids[], description }.
 */
class PlacementObjectRequest extends FormRequest
{
    public function rules(): array
    {
        $currentId = $this->route('placement_object')?->id;

        return [
            /**
             * Nama objek, unik.
             *
             * @example Tea Bridge
             */
            'name' => ['required', 'string', 'max:150', Rule::unique('placement_objects')->ignore($currentId)],
            /** Id kategori objek. */
            'category_id' => ['required', 'string', 'exists:placement_categories,id'],
            /** Id sub unit tempat objek tersedia (minimal satu). */
            'sub_unit_ids' => ['required', 'array', 'min:1'],
            'sub_unit_ids.*' => ['string'],
            'description' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama Objek wajib diisi',
            'name.unique' => 'Objek "'.$this->input('name').'" sudah ada',
            'category_id.required' => 'Pilih Kategori',
            'category_id.exists' => 'Kategori tidak ditemukan',
            'sub_unit_ids.required' => 'Pilih minimal satu Sub Unit',
            'sub_unit_ids.min' => 'Pilih minimal satu Sub Unit',
        ];
    }

    /**
     * Pemeriksaan tambahan setelah aturan di atas: semua sub unit harus ada di master.
     * Pesannya ditaruh di isian "sub_unit_ids" (bukan "sub_unit_ids.0"), sesuai form front end.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $ids = array_unique((array) $this->input('sub_unit_ids', []));

                if ($ids !== [] && SubUnit::whereIn('id', $ids)->count() !== count($ids)) {
                    $validator->errors()->add('sub_unit_ids', 'Sub unit tidak ditemukan');

                    return;
                }

                // Saat ubah: sub unit yang DILEPAS tidak boleh masih punya penugasan aktif di objek ini.
                /** @var PlacementObject|null $object */
                $object = $this->route('placement_object');
                if ($object === null || $ids === []) {
                    return;
                }

                $removedButUsed = SubUnit::query()
                    ->whereNotIn('id', $ids)
                    ->whereHas('assignments', fn ($query) => $query->active()
                        ->whereHas('placementObjects', fn ($query) => $query->whereKey($object->id)))
                    ->pluck('name');

                if ($removedButUsed->isNotEmpty()) {
                    $validator->errors()->add('sub_unit_ids', "{$removedButUsed->join(', ')} masih punya penugasan aktif di objek ini, jadi belum bisa dilepas");
                }
            },
        ];
    }
}
