<?php

namespace Database\Seeders;

use App\Models\OvertimeSetting;
use Illuminate\Database\Seeder;

/**
 * Pengaturan Lembur dengan nilai bawaan (OvertimeSetting::DEFAULTS):
 * pembagi 173, pengali 1,5 / 2, maks. 4 jam/hari & 18 jam/minggu, pembulatan Rp100, Lembur DW mulai 17:00,
 * kelipatan 30 menit. Penanda tangan SPKL & Daftar Upah ada di SignatureSettingSeeder.
 *
 * Kalau pengaturan sudah ada, tidak diubah (supaya isian admin tidak tertimpa).
 */
class OvertimeSettingSeeder extends Seeder
{
    public function run(): void
    {
        OvertimeSetting::current();
    }
}
