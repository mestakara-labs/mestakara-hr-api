<?php

namespace App\Enums;

/**
 * Peran akun login (users.role).
 *
 * Nilai disimpan sebagai teks yang sama persis dengan yang dipakai front end.
 * Saat ini baru satu peran; ke depan ditambah "Ketua Regu" (persetujuan jadwal).
 */
enum UserRole: string
{
    case AdminSdm = 'Admin SDM';
}
