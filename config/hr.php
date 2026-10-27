<?php

/**
 * Pengaturan khusus aplikasi HR Mestakara.
 * Dibaca dengan config('hr.<nama>'), mis. config('hr.unit_name').
 */
return [

    // Nama unit yang tampil di data user login (UserResource.unit_name).
    'unit_name' => env('HR_UNIT_NAME', 'Unit Agrowisata'),

    // Disk Laravel tempat file unggahan disimpan (lihat config/filesystems.php).
    'file_disk' => env('HR_FILE_DISK', 'local'),

    // Awalan alamat file yang dikirim ke front end (mis. photo_url, certificate_file_url).
    // Browser membuka file lewat route handler Next.js (/api/...), yang meneruskannya ke backend
    // dengan token login — jadi alamatnya memakai awalan front end, bukan alamat backend.
    'file_url_prefix' => env('HR_FILE_URL_PREFIX', '/api'),

];
