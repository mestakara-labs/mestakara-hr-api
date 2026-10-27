<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Isian halaman login: { login, password }.
 * `login` berisi NIK atau email.
 */
class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /**
             * NIK atau email.
             *
             * @example admin.sdm@agrowisata.test
             */
            'login' => ['required', 'string', 'max:150'],
            /**
             * Kata sandi.
             *
             * @example Mestakara#2026
             */
            'password' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'login' => 'NIK/Email',
            'password' => 'kata sandi',
        ];
    }
}
