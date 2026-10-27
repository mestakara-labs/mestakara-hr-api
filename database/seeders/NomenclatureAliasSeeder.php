<?php

namespace Database\Seeders;

use App\Enums\AliasStatus;
use App\Enums\NomenclatureType;
use App\Models\NomenclatureAlias;
use App\Models\PlacementObject;
use App\Models\Position;
use App\Models\SubUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * KAMUS NOMENKLATUR: penulisan asli (belum terstandarisasi) -> data standar, dari sheet
 * "Database Karyawan" & "Database Karyawan Standar" (Database Karyawan Unit Agrowisata - Oktober 2026).
 *
 *   POSITIONS          jabatan asli   -> nama jabatan standar (positions.title)
 *   PLACEMENT_OBJECTS  unit asli      -> nama objek penempatan (null = belum dipetakan, mis. nama lokasi umum)
 *   SUB_UNITS          kedudukan asli -> kode afdeling sub unit (null = belum ada sub unitnya)
 *
 * Menambah / mengubah padanan: ubah satu baris di daftar yang sesuai, lalu jalankan
 * php artisan db:seed --class=NomenclatureAliasSeeder
 *
 * Aman dijalankan berulang kali (dicari dari jenis + penulisan asli). Butuh OrganizationSeeder,
 * SubUnitSeeder, dan PlacementObjectSeeder. SUB_UNITS juga dipakai LegacyAssignmentSeeder.
 */
class NomenclatureAliasSeeder extends Seeder
{
    /** @var array<string, string> [jabatan asli => nama jabatan standar] */
    public const POSITIONS = [
        'ADM GUDANG' => 'Admin Gudang',
        'ADM OPS' => 'Admin Operasional',
        'ADM PERSONALIA & UPAH' => 'Admin Personalia Dan Upah',
        'Admin Afdeling' => 'Admin Afdeling',
        'Admin Asuransi & Kesehatan' => 'Admin Asuransi & Kesehatan',
        'Admin Gudang' => 'Admin Gudang',
        'Admin Gudang Ops 2' => 'Admin Gudang',
        'Admin I Food & Beverage' => 'Admin I Food & Beverage',
        'Admin I Kas Bank' => 'Admin I Kas Bank',
        'Admin I SDM dan Umum' => 'Admin I SDM & Umum',
        'Admin I Tanaman I' => 'Admin I Tanaman I',
        'Admin I Tata Usaha' => 'Admin I Tata Usaha',
        'Admin Kas Bank' => 'Admin Kas Bank',
        'Admin Pengadaan' => 'Admin Pengadaan',
        'Admin Personalia & Upah' => 'Admin Personalia Dan Upah',
        'Admin Personalia Dan Upah' => 'Admin Personalia Dan Upah',
        'Admin Tata Buku' => 'Admin Tata Buku',
        'Admin Teknik' => 'Admin Teknik',
        'Admin Umum' => 'Admin Umum',
        'Admin Verifikasi' => 'Admin Verifikasi',
        'ALL IN' => 'All In',
        'Asisten Diperbantukan' => 'Asisten Diperbantukan',
        'Asisten Legal' => 'Asisten Legal',
        'Asisten Manager Food & Beverage' => 'Asisten Manager Food And Beverage',
        'Asisten Manager Operasional' => 'Asisten Manager Operasional',
        'Asisten Manager Umum dan Legal' => 'Asisten Manager Umum Dan Legal',
        'Asisten Operasional Food & Beverage Wilayah I' => 'Asisten Operasional Food & Beverage Wilayah I',
        'Asisten Operasional Food & Beverage Wilayah II' => 'Asisten Operasional Food & Beverage Wilayah II',
        'Asisten SDM & Umum' => 'Asisten SDM & Umum',
        'Asisten Tata Usaha' => 'Asisten Tata Usaha',
        'Asisten Teknik' => 'Asisten Teknik',
        'BAR/SERVER' => 'Karyawan Pelaksana Resto Cafe',
        'BARISTA' => 'Karyawan Pelaksana Resto Cafe',
        'Bongkar Muat' => 'Bongkar Muat',
        'CASHIER' => 'Karyawan Pelaksana Resto Cafe',
        'CLEANING SERVICE' => 'Pelaksana Cleaning Service',
        'COOK' => 'Karyawan Pelaksana Resto Cafe',
        'COOK HELPER' => 'Karyawan Pelaksana Resto Cafe',
        'Danton' => 'Danton',
        'Driver' => 'Sopir',
        'DW CASHIER' => 'Karyawan Pelaksana Resto Cafe',
        'DW SERVER' => 'Karyawan Pelaksana Resto Cafe',
        'FnB Catering' => 'Karyawan Pelaksana Catering',
        'FO Rancabali' => 'Pelaksana Resepsionis',
        'Front Office' => 'Pelaksana Resepsionis',
        'Front Office Glamping' => 'Pelaksana Resepsionis',
        'HEAD BAR' => 'Head Bar',
        'HEAD COOK' => 'Head Cook',
        'HELPER' => 'Karyawan Pelaksana Resto Cafe',
        'House Keeping Vila Merah' => 'Pelaksana House Keeping',
        'JR BARISTA' => 'Karyawan Pelaksana Resto Cafe',
        'JR COOK' => 'Karyawan Pelaksana Resto Cafe',
        'JUNIOR COOK' => 'Karyawan Pelaksana Resto Cafe',
        'Juru Timbang Kebun' => 'Juru Timbang Kebun',
        'Kar. House Keeping Pondokan' => 'Pelaksana House Keeping',
        'Kary Bell Boy' => 'Karyawan Pelaksana Bell Boy',
        'KARY CLEANING SERVICE ATTAAWUN' => 'Pelaksana Cleaning Service',
        'Kary Infrastuktur' => 'Karyawan Infrastruktur & Sipil',
        'Kary Kasir Holly Tea' => 'Karyawan Pelaksana Resto Cafe',
        'KARY KEAMANAN ATTAAWUN' => 'Satpam',
        'Kary Loundry' => 'Pelaksana Laundry',
        'Kary Operator Wahana Bumper Boat' => 'Pelaksana Wahana',
        'Kary Operator Wahana Rainbow Slide' => 'Pelaksana Wahana',
        'KARY PARKIR' => 'Karyawan Parkir',
        'Kary Pemasaran' => 'Karyawan Pemasaran',
        'Kary Pemasaran ( Dago )' => 'Karyawan Pemasaran',
        'Kary Pemeliharaan' => 'Karyawan Pemeliharaan',
        'Kary Resevasi ( Online )' => 'Karyawan Reservasi',
        'KARY TBM' => 'Karyawan TBM',
        'KARY TICKET ATTAAWUN' => 'Pelaksana Ticketing',
        'Kary Ticketing ATV New' => 'Pelaksana Ticketing',
        'Kary Ticketing ATV Sirkuit' => 'Pelaksana Ticketing',
        'Kary Ticketing Gate I' => 'Pelaksana Ticketing',
        'Kary Ticketing Gate II' => 'Pelaksana Ticketing',
        'Kary Ticketing Gate Rawadulang' => 'Pelaksana Ticketing',
        'Kary Ticketing Go Kart' => 'Pelaksana Ticketing',
        'Kary Ticketing Kolam Renang' => 'Pelaksana Ticketing',
        'Kary Ticketing Playing' => 'Pelaksana Ticketing',
        'Kary Ticketing Rest Area' => 'Pelaksana Ticketing',
        'Kary Ticketing Sepeda Listrik' => 'Pelaksana Ticketing',
        'Kary Ticketing Tea Bridge' => 'Pelaksana Ticketing',
        'Kary Ticketing Tea Zone' => 'Pelaksana Ticketing',
        'Kary Ticketing Wahana Animal' => 'Pelaksana Ticketing',
        'Kary Ticketing Wahana Bumper Car' => 'Pelaksana Ticketing',
        'Kary Ticketing Wahana Kereta Gantung' => 'Pelaksana Ticketing',
        'Kary Ticketing Wahana Kuda Tunggang' => 'Pelaksana Ticketing',
        'Kary Ticketing Wahana Rainbow' => 'Pelaksana Ticketing',
        'Kary. Administrasi' => 'Karyawan Administrasi',
        'Kary. Cleaning Service' => 'Pelaksana Cleaning Service',
        'Kary. Cleaning Service ( Babad )' => 'Pelaksana Cleaning Service',
        'Kary. Cleaning Service ( Toilet )' => 'Pelaksana Cleaning Service',
        'KARY. CLEANING SERVICE MAL' => 'Pelaksana Cleaning Service',
        'Kary. Cleaning Service Plataran' => 'Pelaksana Cleaning Service',
        'Kary. House Keeping Glamping' => 'Pelaksana House Keeping',
        'Kary. House Keeping Wisma Affandi' => 'Pelaksana House Keeping',
        'Kary. Kasir Café Injanie' => 'Karyawan Pelaksana Resto Cafe',
        'Kary. Mekanik' => 'Mekanik',
        'Kary. Operator Atv' => 'Pelaksana Wahana',
        'Kary. Operator Atv Sirkuit' => 'Pelaksana Wahana',
        'Kary. Operator Fin Komodo' => 'Pelaksana Wahana',
        'Kary. Operator Selis' => 'Pelaksana Wahana',
        'Kary. Operator Wahana Mini Jeep' => 'Pelaksana Wahana',
        'Kary. Pemasaran' => 'Karyawan Pemasaran',
        'KARY. RETRIBUSI MAL' => 'Petugas Retribusi',
        'KARY. RETRIBUSI TEH JANGKUNG' => 'Petugas Retribusi',
        'Kary. Sarpras' => 'Karyawan Infrastruktur & Sipil',
        'Kary. Sarpras ( Instalasi Air )' => 'Pelaksana Teknik Inst Listrik & Air',
        'Kary. Sarpras ( Instalasi Listrik )' => 'Pelaksana Teknik Inst Listrik & Air',
        'Kary. Satpam' => 'Satpam',
        'KARY. SATPAM MAL' => 'Satpam',
        'Kary. Tea Corner/Tea Cafe' => 'Karyawan Pelaksana Resto Cafe',
        'Karyawan Pelaksana Bell Boy' => 'Karyawan Pelaksana Bell Boy',
        'Karyawan Pelaksana Resto Cafe' => 'Karyawan Pelaksana Resto Cafe',
        'Karyawan Pemasaran' => 'Karyawan Pemasaran',
        'Karyawan Pemeliharaan' => 'Karyawan Pemeliharaan',
        'Karyawan Pemeliharaan Teh Lancuran' => 'Karyawan Pemeliharaan Teh Lancuran',
        'KASIR' => 'Karyawan Pelaksana Resto Cafe',
        'Kay Gallery' => 'Karyawan Gallery',
        'Kay Pemasaran ( Dago )' => 'Karyawan Pemasaran',
        'Kay. Pemandu Tea Walk' => 'Karyawan Pemandu Wisata',
        'LEADER' => 'Leader',
        'Mandor I Tanaman I' => 'Mandor I Tanaman I',
        'Mandor Listrik dan Pembangkit' => 'Mandor Listrik dan Pembangkit',
        'Mandor Panen Gunting / Manual' => 'Mandor Panen Gunting / Manual',
        'Mandor Pemangkasan' => 'Mandor Pemangkasan',
        'Mandor Rawat Umum' => 'Mandor Rawat Umum',
        'MARKETING' => 'Karyawan Pemasaran',
        'Mekanik' => 'Mekanik',
        'Montir' => 'Montir',
        'OP Rancabali' => 'Karyawan Operasional',
        'Pelaksana Cleaning Service' => 'Pelaksana Cleaning Service',
        'Pelaksana Gudang' => 'Pelaksana Gudang',
        'Pelaksana Hama Penyakit' => 'Pelaksana Hama Penyakit',
        'Pelaksana House Keeping' => 'Pelaksana House Keeping',
        'Pelaksana Laundry' => 'Pelaksana Laundry',
        'Pelaksana Panen Dengan Mesin Petik' => 'Pelaksana Panen Dengan Mesin Petik',
        'Pelaksana Penyiangan Kimiawi' => 'Pelaksana Penyiangan Kimiawi',
        'Pelaksana Pertamanan' => 'Pelaksana Pertamanan',
        'Pelaksana Rawat Umum' => 'Pelaksana Rawat Umum',
        'Pelaksana Resepsionis' => 'Pelaksana Resepsionis',
        'Pelaksana Teknik Inst Listrik & Air' => 'Pelaksana Teknik Inst Listrik & Air',
        'Pelaksana Ticketing' => 'Pelaksana Ticketing',
        'Pelayan Kantor' => 'Pelayan Kantor',
        'PENANGGUNG JAWAB ATTAAWUN' => 'Penanggung Jawab Unit',
        'Penanggung Jawab Food And Beverage' => 'Penanggung Jawab Food And Beverage',
        'Penanggung Jawab House Keeping' => 'Penanggung Jawab House Keeping',
        'Penanggung Jawab Pemeliharaan Kebersihan' => 'Penanggung Jawab Pemeliharaan Kebersihan',
        'Penanggung Jawab Pemeliharaan Sipil' => 'Penanggung Jawab Pemeliharaan Sipil',
        'Penanggung Jawab Resto Cafe' => 'Penanggung Jawab Resto Cafe',
        'Penanggung Jawab Teknik Inst Listrik & Air' => 'Penanggung Jawab Teknik Inst Listrik & Air',
        'Penanggung Jawab Ticketing' => 'Penanggung Jawab Ticketing',
        'Penangungjawab Resepsionis' => 'Penangungjawab Resepsionis',
        'PENGAWAS' => 'Pengawas',
        'Pengawas Infrastruktur' => 'Pengawas Infrastruktur',
        'Pengawas Operasional' => 'Pengawas Operasional',
        'Pengawas Pelayanan I' => 'Pengawas Pelayanan I',
        'Pengawas Teknik Mesin, Instalasi Air & Listrik, Kendaraan' => 'Pengawas Teknik Mesin, Instalasi Air & Listrik, Kendaraan',
        'Penyelia Sistem Manajemen' => 'Penyelia Sistem Manajemen',
        'PET MARCHANDISE' => 'Petugas Merchandise',
        'PET RETRIBUSI MASUK' => 'Petugas Retribusi',
        'PET RETRIBUSI PARKIR' => 'Petugas Retribusi',
        'Petugas Mess' => 'Petugas Mess',
        'Plh. Manajer' => 'Manager',
        'PT BAR' => 'Karyawan Pelaksana Resto Cafe',
        'Satpam' => 'Satpam',
        'SENIOR COOK' => 'Karyawan Pelaksana Resto Cafe',
        'SERVER' => 'Karyawan Pelaksana Resto Cafe',
        'Sopir' => 'Sopir',
        'SPV' => 'Supervisor',
        'STORE KEEPER' => 'Pelaksana Gudang',
        'SUPERVISOR' => 'Supervisor',
        'SVP' => 'Supervisor',
        'Teknisi Alat Pertanian' => 'Teknisi Alat Pertanian',
        'Teknisi Listrik' => 'Teknisi Listrik',
        'TENAGA KITCHEN & LAUNDRY MAL' => 'Tenaga Kitchen & Laundry',
        'Tenaga Pengajar' => 'Tenaga Pengajar',
        'TENAGA RESQUE & MAINTENANCE MAL' => 'Tenaga Rescue & Maintenance',
        'TENAGA TEA BRIDGE & MAINTENANCE MAL' => 'Tenaga Operator & Maintenance',
        'Ticketing Gate Rawadulang' => 'Pelaksana Ticketing',
        'Ticketing Gatte 1' => 'Pelaksana Ticketing',
        'Ticketing Gatte 2' => 'Pelaksana Ticketing',
        'Ticketing Wahana Adventure' => 'Pelaksana Ticketing',
        'Ticketing Wahana CV Bukit 300' => 'Pelaksana Ticketing',
        'Ticketing Wahana Kereta Wisata' => 'Pelaksana Ticketing',
        'Ticketing Wahana Selis' => 'Pelaksana Ticketing',
        'WAITER' => 'Karyawan Pelaksana Resto Cafe',
    ];

    /** @var array<string, ?string> [unit asli => nama objek penempatan, null = belum dipetakan] */
    public const PLACEMENT_OBJECTS = [
        'AGRO GUM' => null,
        'AGRO MAL' => null,
        'AGRO RAN' => null,
        'ATTAAWUN' => 'Attaawun',
        'BANDUNG' => null,
        'BOSSCHA SPACE' => 'Bosscha Space',
        'BUNUT' => 'Cafe Bunut',
        'CATERING GUM' => 'Catering Gunung Mas',
        'CIATER' => null,
        'DUSUN BAMBU' => 'Dusun Bambu',
        'GAMUHANG' => 'Gamuhang',
        'GUNUNG MAS' => null,
        'HI TEA' => 'Hi Tea',
        'MALABAR' => null,
        'MANOE CAFÉ' => 'Manoe Café',
        'OFFICE F&B BOSSCHA' => 'Office F&B Bosscha',
        'PARKIR' => 'Parkir',
        'PASIR BADAK' => null,
        'RANCABALI' => null,
        'TEA CAFÉ' => 'Tea Corner / Tea Cafe',
        'TEA CORNER' => 'Tea Corner / Tea Cafe',
        'TEH JANGKUNG' => 'Teh Jangkung',
        'VILLA MERAH' => 'Vila Merah',
    ];

    /** @var array<string, ?string> [kedudukan asli => kode afdeling sub unit, null = belum dipetakan] */
    public const SUB_UNITS = [
        'AFD.GUNUNG MAS' => 'AFD50',
        'BANDUNG' => null,
        'CIATER' => 'AFD06',
        'GUNUNG MAS' => 'AFD03',
        'KANTOR INDUK' => 'AFD02',
        'MALABAR' => 'AFD04',
        'PASIR BADAK' => 'AFD09',
        'RANCABALI' => 'AFD05',
        'SUBANG' => null,
        'SUKABUMI' => null,
    ];

    public function run(): void
    {
        $positionIds = Position::pluck('id', 'title');
        $objectIds = PlacementObject::pluck('id', 'name');
        $subUnitIds = SubUnit::pluck('id', 'afdeling_code');

        DB::transaction(function () use ($positionIds, $objectIds, $subUnitIds) {
            foreach (self::POSITIONS as $alias => $title) {
                $this->save(NomenclatureType::Position, $alias, ['position_id' => $positionIds[$title]]);
            }

            foreach (self::PLACEMENT_OBJECTS as $alias => $name) {
                $this->save(NomenclatureType::PlacementObject, $alias, ['placement_object_id' => $name === null ? null : $objectIds[$name]]);
            }

            foreach (self::SUB_UNITS as $alias => $afdelingCode) {
                $this->save(NomenclatureType::SubUnit, $alias, ['sub_unit_id' => $afdelingCode === null ? null : $subUnitIds[$afdelingCode]]);
            }
        });
    }

    /**
     * Simpan satu penulisan asli beserta padanannya. Tanpa padanan = Belum Dipetakan.
     *
     * @param  array<string, ?string>  $target  mis. ['position_id' => '...']
     */
    private function save(NomenclatureType $type, string $alias, array $target): void
    {
        $isMapped = array_filter($target) !== [];

        NomenclatureAlias::updateOrCreate(['type' => $type, 'alias' => $alias], $target + [
            'status' => $isMapped ? AliasStatus::Mapped : AliasStatus::Unmapped,
        ]);
    }
}
