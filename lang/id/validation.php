<?php

/**
 * Pesan validasi Bahasa Indonesia (APP_LOCALE=id).
 *
 * Hanya aturan yang dipakai aplikasi ini. Aturan yang tidak ada di sini
 * otomatis memakai pesan bahasa Inggris bawaan Laravel (APP_FALLBACK_LOCALE=en).
 *
 * :attribute diganti nama isian dari method attributes() di setiap FormRequest.
 */
return [

    'accepted' => ':attribute harus disetujui.',
    'after' => ':attribute harus setelah :date.',
    'after_or_equal' => ':attribute harus sama dengan atau setelah :date.',
    'array' => ':attribute harus berupa daftar.',
    'before' => ':attribute harus sebelum :date.',
    'before_or_equal' => ':attribute tidak boleh melewati :date.',
    'between' => [
        'numeric' => ':attribute harus antara :min dan :max.',
        'string' => ':attribute harus antara :min dan :max karakter.',
        'array' => ':attribute harus berisi :min sampai :max data.',
    ],
    'boolean' => ':attribute harus bernilai ya atau tidak.',
    'date' => ':attribute bukan tanggal yang benar.',
    'date_format' => ':attribute harus berformat :format.',
    'different' => ':attribute dan :other harus berbeda.',
    'digits' => ':attribute harus :digits digit angka.',
    'digits_between' => ':attribute harus :min sampai :max digit angka.',
    'distinct' => ':attribute tidak boleh ada yang sama.',
    'email' => ':attribute bukan alamat email yang benar.',
    'exists' => ':attribute yang dipilih tidak ditemukan.',
    'file' => ':attribute harus berupa file.',
    'filled' => ':attribute wajib diisi.',
    'image' => ':attribute harus berupa gambar.',
    'in' => ':attribute yang dipilih tidak tersedia.',
    'integer' => ':attribute harus berupa angka bulat.',
    'json' => ':attribute harus berupa teks JSON yang benar.',
    'max' => [
        'numeric' => ':attribute tidak boleh lebih dari :max.',
        'file' => 'Ukuran :attribute tidak boleh lebih dari :max KB.',
        'string' => ':attribute tidak boleh lebih dari :max karakter.',
        'array' => ':attribute tidak boleh lebih dari :max data.',
    ],
    'mimes' => ':attribute harus berupa file: :values.',
    'mimetypes' => ':attribute harus berupa file: :values.',
    'min' => [
        'numeric' => ':attribute minimal :min.',
        'file' => 'Ukuran :attribute minimal :min KB.',
        'string' => ':attribute minimal :min karakter.',
        'array' => ':attribute minimal berisi :min data.',
    ],
    'numeric' => ':attribute harus berupa angka.',
    'present' => ':attribute wajib dikirim.',
    'regex' => 'Format :attribute tidak benar.',
    'required' => ':attribute wajib diisi.',
    'required_if' => ':attribute wajib diisi kalau :other adalah :value.',
    'required_with' => ':attribute wajib diisi kalau :values diisi.',
    'size' => [
        'numeric' => ':attribute harus :size.',
        'string' => ':attribute harus :size karakter.',
        'array' => ':attribute harus berisi :size data.',
    ],
    'string' => ':attribute harus berupa teks.',
    'unique' => ':attribute sudah dipakai.',

    /*
     * Nama isian bawaan. Bisa ditimpa per FormRequest lewat method attributes().
     */
    'attributes' => [
        'name' => 'nama',
        'code' => 'kode',
        'description' => 'keterangan',
    ],

];
