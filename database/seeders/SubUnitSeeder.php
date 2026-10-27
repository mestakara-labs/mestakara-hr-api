<?php

namespace Database\Seeders;

use App\Models\SubUnit;
use Illuminate\Database\Seeder;

/**
 * Master Sub Unit / afdeling Unit Agrowisata.
 *
 * Aman dijalankan berulang kali: sub unit dicari dari kode afdeling (updateOrCreate),
 * jadi nama yang diubah di sini ikut diperbarui tanpa membuat data ganda.
 */
class SubUnitSeeder extends Seeder
{
    /** @var array<string, string> [kode afdeling => nama sub unit] */
    public const SUB_UNITS = [
        'AFD02' => 'Kantor Induk',
        'AFD03' => 'Gunung Mas',
        'AFD04' => 'Malabar',
        'AFD05' => 'Rancabali',
        'AFD06' => 'Ciater',
        'AFD09' => 'Pasir Badak',
        'AFD50' => 'Afdeling Gunung Mas',
    ];

    public function run(): void
    {
        foreach (self::SUB_UNITS as $afdelingCode => $name) {
            SubUnit::updateOrCreate(['afdeling_code' => $afdelingCode], ['name' => $name]);
        }
    }
}
