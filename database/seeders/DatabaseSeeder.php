<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Data awal aplikasi. Jalankan dengan: php artisan migrate --seed
 *
 * Akun login + struktur organisasi Unit Agrowisata (SK, Bidang, Jabatan) + Sub Unit + objek penempatan + data awal karyawan, penempatan & atasannya (September 2026)
 * + kamus nomenklatur & jabatan asli karyawan (Oktober 2026, hanya referensi)
 * + data dasar upah: cost center, kelompok daftar upah, pengaturan lembur, penanda tangan umum, dan skema upah / rekening / cost center
 *   karyawan Casual & DW (Daftar Upah September 2026, berlaku 1 Oktober 2026).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            OrganizationSeeder::class,
            SubUnitSeeder::class,
            PlacementObjectSeeder::class,
            EmployeeSeeder::class,
            PositionAssignmentSeeder::class,
            NomenclatureAliasSeeder::class,
            LegacyAssignmentSeeder::class,
            CostCenterSeeder::class,
            PayrollGroupSeeder::class,
            OvertimeSettingSeeder::class,
            SignatureSettingSeeder::class,
            WageSchemeSeeder::class,
        ]);
    }
}
