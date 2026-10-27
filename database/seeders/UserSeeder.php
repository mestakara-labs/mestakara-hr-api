<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Akun login awal — SAMA dengan akun uji di front end (src/lib/mock/auth-mock.ts).
 *
 *   NIK        Email                          Kata sandi
 *   10928374   admin.sdm@agrowisata.test      Mestakara#2026
 *   10928375   staf.sdm@agrowisata.test       Mestakara#2026
 *
 * PENTING: ganti kata sandi ini sebelum aplikasi dipakai di server produksi.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['nik' => '10928374', 'name' => 'Admin SDM', 'email' => 'admin.sdm@agrowisata.test'],
            ['nik' => '10928375', 'name' => 'Staf SDM', 'email' => 'staf.sdm@agrowisata.test'],
        ];

        foreach ($accounts as $account) {
            // updateOrCreate: seeder aman dijalankan berulang kali tanpa membuat akun ganda.
            User::updateOrCreate(['nik' => $account['nik']], [
                'name' => $account['name'],
                'email' => $account['email'],
                'password' => 'Mestakara#2026',   // otomatis di-hash (cast 'hashed' di model User)
                'role' => UserRole::AdminSdm,
                'is_active' => true,
            ]);
        }
    }
}
