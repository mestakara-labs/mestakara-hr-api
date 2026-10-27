<?php

namespace App\Http\Requests\Schedule;

use App\Enums\HolidayType;
use App\Models\Holiday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Isian form Hari Libur (tambah & ubah): { date, name, type, description }. Tanggal unik.
 */
class HolidayRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /**
             * Tanggal "YYYY-MM-DD" (unik).
             *
             * @example 2026-08-17
             */
            'date' => ['required', 'date_format:Y-m-d'],
            /**
             * Nama hari libur.
             *
             * @example Hari Kemerdekaan RI
             */
            'name' => ['required', 'string', 'max:150'],
            /** Libur Nasional / Cuti Bersama / Libur Perusahaan. */
            'type' => ['required', Rule::in(HolidayType::values())],
            'description' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.*' => 'Pilih Tanggal',
            'name.required' => 'Nama Hari Libur wajib diisi',
            'type.*' => 'Pilih Jenis',
        ];
    }

    /** Satu tanggal hanya boleh tercatat sekali. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('date')) {
                    return;
                }

                $sameDate = Holiday::query()
                    ->whereDate('date', $this->input('date'))
                    ->when($this->route('holiday'), fn ($query, $current) => $query->whereKeyNot($current->id))
                    ->first();

                if ($sameDate !== null) {
                    $validator->errors()->add('date', "Tanggal ini sudah tercatat sebagai \"{$sameDate->name}\"");
                }
            },
        ];
    }
}
