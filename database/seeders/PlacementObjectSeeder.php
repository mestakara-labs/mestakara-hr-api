<?php

namespace Database\Seeders;

use App\Models\PlacementCategory;
use App\Models\PlacementObject;
use App\Models\SubUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Master KATEGORI OBJEK & OBJEK PENEMPATAN Unit Agrowisata, dari sheet "Pemetaan Jabatan" & "Nomenklatur AGRO"
 * (Database Karyawan PTPN I Regional 2 Unit Agrowisata - Oktober 2026).
 * Objek kategori Food & Beverage ditempatkan di Gunung Mas; objek kategori Administrasi di Kantor Induk.
 *
 * Bentuk data: [kategori => [objek => [kode afdeling sub unit tempat objek itu tersedia]]].
 * Kode afdeling sesuai SubUnitSeeder: AFD02 Kantor Induk, AFD03 Gunung Mas, AFD04 Malabar, AFD05 Rancabali, AFD06 Ciater.
 *
 * Menambah objek: tulis satu baris di kategori yang sesuai, mis. 'Flying Fox' => ['AFD03'],
 * lalu jalankan: php artisan db:seed --class=PlacementObjectSeeder
 *
 * Aman dijalankan berulang kali: kategori & objek dicari dari namanya (updateOrCreate),
 * dan daftar sub unit tiap objek disamakan dengan data di bawah.
 * Butuh SubUnitSeeder.
 */
class PlacementObjectSeeder extends Seeder
{
    /** @var array<string, array<string, list<string>>> */
    public const OBJECTS = [
        'Wahana' => [
            'Adventure' => ['AFD03'],
            'Animal' => ['AFD03'],
            'ATV' => ['AFD03'],
            'ATV New' => ['AFD03'],
            'ATV Sirkuit' => ['AFD03'],
            'Bumper Boat' => ['AFD03'],
            'Bumper Car' => ['AFD03'],
            'Fin Komodo' => ['AFD03'],
            'Gallery' => ['AFD03'],
            'Go Kart' => ['AFD03'],
            'Kereta Gantung' => ['AFD03'],
            'Kereta Wisata' => ['AFD03'],
            'Kolam Renang' => ['AFD03'],
            'Kuda Tunggang' => ['AFD03'],
            'Mini Jeep' => ['AFD03'],
            'Playing' => ['AFD03'],
            'Rainbow Slide' => ['AFD03'],
            'Selis' => ['AFD03'],
            'Sepeda Listrik' => ['AFD03'],
            'Tea Bridge' => ['AFD03'],
            'Tea Walk' => ['AFD03'],
            'Tea Zone' => ['AFD03'],
        ],
        'Ticket' => [
            'Attaawun' => ['AFD03'],
            'CV Bukit 300' => ['AFD03'],
            'Gate I' => ['AFD03'],
            'Gate II' => ['AFD03'],
            'Gate Rawadulang' => ['AFD03'],
            'Rest Area' => ['AFD03'],
            'Teh Jangkung' => ['AFD04'],
            'Parkir' => ['AFD03'],
        ],
        'Food & Beverage' => [
            'Café Injanie' => ['AFD03'],
            'Holly Tea' => ['AFD03'],
            'Tea Corner / Tea Cafe' => ['AFD03'],
            'Hi Tea' => ['AFD03'],
            'Bosscha Space' => ['AFD03'],
            'Manoe Café' => ['AFD03'],
            'Dusun Bambu' => ['AFD03'],
            'Catering Gunung Mas' => ['AFD03'],
            'Cafe Bunut' => ['AFD03'],
            'Gamuhang' => ['AFD03'],
        ],
        'Penginapan' => [
            'Glamping' => ['AFD03'],
            'Plataran' => ['AFD03'],
            'Pondokan' => ['AFD03'],
            'Vila Merah' => ['AFD06'],
            'Wisma Affandi' => ['AFD03'],
        ],
        'Administrasi' => [
            'Gudang Ops 2' => ['AFD02'],
            'Office Dago' => ['AFD02'],
            'Office F&B Bosscha' => ['AFD02'],
        ],
    ];

    public function run(): void
    {
        $subUnitIds = SubUnit::pluck('id', 'afdeling_code');

        DB::transaction(function () use ($subUnitIds) {
            foreach (self::OBJECTS as $categoryName => $objects) {
                $category = PlacementCategory::updateOrCreate(['name' => $categoryName]);

                foreach ($objects as $objectName => $afdelingCodes) {
                    $object = PlacementObject::updateOrCreate(['name' => $objectName], ['category_id' => $category->id]);

                    $object->subUnits()->sync(array_map(fn (string $code) => $subUnitIds[$code], $afdelingCodes));
                }
            }
        });
    }
}
