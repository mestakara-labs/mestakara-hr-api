<?php

namespace Database\Seeders;

use App\Models\PayrollGroup;
use Illuminate\Database\Seeder;

/**
 * Kelompok Daftar Upah karyawan Casual & Daily Worker, urut seperti di file Daftar Upah.
 * Aman dijalankan berulang kali (updateOrCreate per nama).
 */
class PayrollGroupSeeder extends Seeder
{
    public const GROUPS = [
        'Gunung Mas',
        'F&B Gunung Mas',
        'Bosscha Staff',
        'Bosscha SPP PT',
        'Petugas Parkir',
        'Atta Awun',
        'Malabar',
        'Rancabali',
        'Bunut',
    ];

    public function run(): void
    {
        foreach (self::GROUPS as $index => $name) {
            PayrollGroup::updateOrCreate(['name' => $name], ['sort_order' => $index + 1]);
        }
    }
}
