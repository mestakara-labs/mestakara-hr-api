<?php

namespace App\Http\Requests\Schedule;

use App\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Isian form Shift (tambah & ubah). Kode boleh sama dengan shift lain (dibedakan warna & nama);
 * nama unik. Kode disimpan huruf besar.
 */
class ShiftRequest extends FormRequest
{
    private const TIME_FORMAT = '/^([01]\d|2[0-3]):[0-5]\d$/';

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        return [
            /**
             * Kode di kalender (maks. 4 huruf).
             *
             * @example P
             */
            'code' => ['required', 'string', 'max:4'],
            /**
             * Nama shift, unik.
             *
             * @example Pagi
             */
            'name' => ['required', 'string', 'max:100', Rule::unique('shifts')->ignore($this->route('shift')?->id)],
            /**
             * Jam mulai "HH:MM".
             *
             * @example 07:00
             */
            'start_time' => ['required', 'regex:'.self::TIME_FORMAT],
            /**
             * Jam selesai "HH:MM". Lebih kecil / sama dengan jam mulai = selesai keesokan harinya.
             *
             * @example 12:00
             */
            'end_time' => ['required', 'regex:'.self::TIME_FORMAT, 'different:start_time'],
            'break_minutes' => ['required', 'integer', 'between:0,240'],
            'late_tolerance_minutes' => ['required', 'integer', 'between:0,120'],
            'checkin_window_before_minutes' => ['required', 'integer', 'between:0,720'],
            'checkout_window_after_minutes' => ['required', 'integer', 'between:0,720'],
            /**
             * Warna HEX.
             *
             * @example #1BAA4E
             */
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'description' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Kode Shift wajib diisi',
            'code.max' => 'Kode Shift maksimal 4 karakter',
            'name.required' => 'Nama Shift wajib diisi',
            'name.unique' => 'Shift "'.$this->input('name').'" sudah ada',
            'start_time.*' => 'Isi Jam Mulai dengan benar (JJ:MM)',
            'end_time.required' => 'Isi Jam Selesai dengan benar (JJ:MM)',
            'end_time.regex' => 'Isi Jam Selesai dengan benar (JJ:MM)',
            'end_time.different' => 'Jam Selesai tidak boleh sama dengan Jam Mulai',
            'break_minutes.*' => 'Istirahat harus 0–240 menit',
            'late_tolerance_minutes.*' => 'Toleransi harus 0–120 menit',
            'checkin_window_before_minutes.*' => 'Batas scan masuk harus 0–720 menit',
            'checkout_window_after_minutes.*' => 'Batas scan pulang harus 0–720 menit',
            'color.*' => 'Pilih Warna atau isi kode warna yang benar',
        ];
    }

    /** Istirahat harus lebih singkat dari lama shift. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['start_time', 'end_time', 'break_minutes'])) {
                    return;
                }

                $duration = Shift::durationBetween($this->input('start_time'), $this->input('end_time'));
                if ((int) $this->input('break_minutes') >= $duration) {
                    $validator->errors()->add('break_minutes', 'Istirahat harus lebih singkat dari lama shift');
                }
            },
        ];
    }
}
