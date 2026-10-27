<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\OrganizationDecree;
use App\Models\Position;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Struktur organisasi Unit Agrowisata menurut SK Direksi PTPN VIII
 * Nomor KEP/IV.2/1667/XI/2023 tanggal 9 November 2023 (Lampiran IX.1 – IX.9):
 *
 *   1. SK struktur organisasi  -> organization_decrees (dijadikan satu-satunya SK yang berlaku)
 *   2. Bidang                  -> divisions, satu per fungsi; Operasional Wilayah I & II digabung menjadi bidang Operasional (OPS)
 *   3. Jabatan                 -> positions, semua kotak di bagan lampiran + jabatan tambahan di luar SK
 *
 * Strata di bagan SK diubah ke level BRM: Strata 6 = BRM-1 (Manager) … Strata 1 = BRM-6 (Pelaksana).
 * Kotak bertumpuk di bagan (pemegang lebih dari satu orang) tetap SATU jabatan; pemegangnya diatur
 * lewat penugasan. Garis atasan juga tidak disimpan di sini, tetapi per penugasan (Mutasi & Penempatan).
 *
 * Aman dijalankan berulang kali: data dicari dari kode / nomor SK (updateOrCreate).
 */
class OrganizationSeeder extends Seeder
{
    public const DECREE_NUMBER = 'KEP/IV.2/1667/XI/2023';

    public const DECREE_DATE = '2023-11-09';

    /** Manager unit (Strata 6), tanpa bidang. */
    private const MANAGER = ['code' => 'JAB-001', 'title' => 'Manager', 'description' => 'Pimpinan Unit Agrowisata (Lampiran IX.1).'];

    /**
     * Bidang + jabatannya, urut sesuai Lampiran IX.2 – IX.9.
     * Setiap jabatan: [strata, nama jabatan, keterangan (opsional)].
     *
     * @var list<array{code: string, name: string, description: string, positions: list<array{0: int, 1: string, 2?: string}>}>
     */
    private const DIVISIONS = [
        [
            'code' => 'ADK',
            'name' => 'Administrasi dan Keuangan',
            'description' => 'Lampiran IX.2 — Fungsi Administrasi & Keuangan.',
            'positions' => [
                [5, 'Asisten Manager Administrasi dan Keuangan'],
                [4, 'Asisten Verifikasi', 'Garis koordinasi dengan Manager & Asisten Manager Administrasi dan Keuangan.'],
                [4, 'Asisten Tata Usaha'],
                [4, 'Asisten Personalia'],
                [3, 'Admin I Verifikasi'],
                [3, 'Admin I Tata Usaha'],
                [3, 'Admin I Kas Bank'],
                [3, 'Admin I Personalia dan Upah'],
                [2, 'Admin Verifikasi'],
                [2, 'Admin Tata Buku'],
                [2, 'Admin Gudang'],
                [2, 'Admin Pengadaan'],
                [2, 'Admin Kas Bank'],
                [2, 'Admin Personalia Dan Upah'],
                [1, 'Karyawan Gudang'],
            ],
        ],
        [
            'code' => 'UML',
            'name' => 'Umum dan Legal',
            'description' => 'Lampiran IX.3 — Fungsi Umum dan Legal.',
            'positions' => [
                [5, 'Asisten Manager Umum Dan Legal'],
                [4, 'Asisten Umum'],
                [4, 'Asisten Legal'],
                [3, 'Admin I Umum'],
                [3, 'Admin I Legal'],
                [2, 'Admin Asuransi & Kesehatan'],
                [2, 'Admin Umum'],
                [2, 'Danton'],
                [2, 'Admin Legal'],
                [1, 'Pelayan Kantor'],
                [1, 'Satpam'],
            ],
        ],
        [
            'code' => 'INS',
            'name' => 'Infrastruktur dan Sipil',
            'description' => 'Lampiran IX.4 — Fungsi Infrastruktur dan Sipil.',
            'positions' => [
                [5, 'Asisten Manager Infrastruktur dan Sipil'],
                [4, 'Asisten IT'],
                [4, 'Asisten Infrastruktur & Sipil'],
                [4, 'Asisten Teknik'],
                [3, 'Pengawas IT'],
                [3, 'Admin I', 'Admin I bidang Infrastruktur dan Sipil.'],
                [3, 'Pengawas Sipil'],
                [3, 'Pengawas Infrastruktur'],
                [3, 'Pengawas Teknik Mesin, Instalasi Air & Listrik, Kendaraan'],
                [1, 'Karyawan Infrastruktur & Sipil'],
            ],
        ],
        [
            'code' => 'PBS',
            'name' => 'Pengembangan Bisnis',
            'description' => 'Lampiran IX.5 — Fungsi Pengembangan Bisnis.',
            'positions' => [
                [5, 'Asisten Manager Pengembangan Bisnis'],
                [4, 'Asisten Pengembangan Bisnis I', 'Wilayah Bandung, Subang, Purwakarta, Garut, Tasik, Ciamis.'],
                [4, 'Asisten Pengembangan Bisnis II', 'Wilayah Sukabumi, Cianjur, Bogor, Rangkasbitung, Lebak, Pandeglang.'],
                [3, 'Pengawas Pengembangan Bisnis I'],
                [3, 'Pengawas Pengembangan Bisnis II'],
                [2, 'Admin Pengembangan Bisnis'],
            ],
        ],
        [
            'code' => 'OPS',
            'name' => 'Operasional',
            'description' => 'Lampiran IX.6 & IX.7 — Operasional Wilayah I (Gunung Mas dan Sukabumi) dan Wilayah II (Ciwidey, Pengalengan, Sukawana dan Ciater), digabung menjadi satu bidang.',
            'positions' => [
                // Lampiran IX.6 — Wilayah I
                [5, 'Asisten Manager Operasional Wilayah I'],
                [4, 'Asisten Operasional I'],
                [4, 'Asisten Tanaman I'],
                [3, 'Pengawas Teknik & Pemeliharaan I'],
                [3, 'Pengawas Pelayanan I'],
                [3, 'Mandor I Tanaman I'],
                [3, 'Admin I Tanaman I'],
                [2, 'Penanggung Jawab Teknik Inst Listrik & Air'],
                [2, 'Penanggung Jawab Teknik Wahana & Kendaraan'],
                [2, 'Penanggung Jawab Pemeliharaan Kebersihan'],
                [2, 'Penanggung Jawab Pemeliharaan Pertamanan'],
                [2, 'Penanggung Jawab Pemeliharaan Sipil'],
                [2, 'Penanggung Jawab Ticketing'],
                [2, 'Penanggung Jawab House Keeping'],
                [2, 'Penanggung Jawab Wahana'],
                [2, 'Admin Operasional Wilayah I'],
                [2, 'Mandor Panen'],
                [2, 'Mandor Rawat Umum'],
                [2, 'Mandor Hama Penyakit'],
                [2, 'Admin Tanaman'],
                [1, 'Pelaksana Teknik Inst Listrik & Air'],
                [1, 'Pelaksana Teknik Wahana & Kendaraan'],
                [1, 'Pelaksana Kebersihan'],
                [1, 'Pelaksana Pertamanan'],
                [1, 'Pelaksana Sipil'],
                [1, 'Pelaksana Ticketing'],
                [1, 'Pelaksana House Keeping'],
                [1, 'Pelaksana Wahana'],
                [1, 'Pelaksana Panen'],
                [1, 'Pelaksana Rawat Umum'],
                [1, 'Pelaksana Hama Penyakit'],
                // Lampiran IX.7 — Wilayah II. Kotak yang namanya sama dengan Wilayah I tidak dibuat lagi
                // (satu jabatan untuk kedua wilayah; wilayahnya dibedakan lewat sub unit penempatan).
                [5, 'Asisten Manager Operasional Wilayah II'],
                [4, 'Asisten Operasional II'],
                [4, 'Asisten Tanaman II'],
                [3, 'Pengawas Teknik & Pemeliharaan II'],
                [3, 'Pengawas Pelayanan II'],
                [3, 'Mandor I Tanaman II'],
                [3, 'Admin I Tanaman II'],
                [2, 'Penanggung Jawab Teknik Inst Listrik & Air'],
                [2, 'Penanggung Jawab Teknik Wahana & Kendaraan'],
                [2, 'Penanggung Jawab Pemeliharaan Kebersihan'],
                [2, 'Penanggung Jawab Pemeliharaan Pertamanan'],
                [2, 'Penanggung Jawab Pemeliharaan Sipil'],
                [2, 'Penanggung Jawab Ticketing'],
                [2, 'Penanggung Jawab House Keeping'],
                [2, 'Penanggung Jawab Wahana'],
                [2, 'Admin Operasional Wilayah II'],
                [2, 'Mandor Panen'],
                [2, 'Mandor Rawat Umum'],
                [2, 'Mandor Hama Penyakit'],
                [2, 'Admin Tanaman'],
                [1, 'Pelaksana Teknik Inst Listrik & Air'],
                [1, 'Pelaksana Teknik Wahana & Kendaraan'],
                [1, 'Pelaksana Kebersihan'],
                [1, 'Pelaksana Pertamanan'],
                [1, 'Pelaksana Sipil'],
                [1, 'Pelaksana Ticketing'],
                [1, 'Pelaksana House Keeping'],
                [1, 'Pelaksana Wahana'],
                [1, 'Pelaksana Panen'],
                [1, 'Pelaksana Rawat Umum'],
                [1, 'Pelaksana Hama Penyakit'],
            ],
        ],
        [
            'code' => 'FNB',
            'name' => 'Food and Beverage',
            'description' => 'Lampiran IX.8 — Fungsi Food & Beverage.',
            'positions' => [
                [5, 'Asisten Manager Food And Beverage'],
                [4, 'Asisten Marketing Food and Beverage'],
                [4, 'Asisten Operasional Food & Beverage Wilayah I', 'Wilayah Bogor, Sukabumi.'],
                [4, 'Asisten Operasional Food & Beverage Wilayah II', 'Wilayah Bandung, Subang.'],
                [3, 'Pengawas Resto Cafe Wilayah I'],
                [3, 'Pengawas Catering Wilayah I'],
                [3, 'Pengawas Resto Cafe Wilayah II'],
                [3, 'Pengawas Catering Wilayah II'],
                [3, 'Admin I Keuangan'],
                [3, 'Admin I Personalia'],
                [2, 'Karyawan Marketing Food and Beverage'],
                [2, 'Penanggung Jawab Resto Cafe Wilayah I'],
                [2, 'Penanggung Jawab Resto Cafe Wilayah II'],
                [2, 'Admin Purchasing'],
                [1, 'Karyawan Pelaksana Resto Cafe Wilayah I'],
                [1, 'Karyawan Pelaksana Catering Wilayah I'],
                [1, 'Karyawan Pelaksana Resto Cafe Wilayah II'],
                [1, 'Karyawan Pelaksana Catering Wilayah II'],
            ],
        ],
        [
            'code' => 'MKS',
            'name' => 'Marketing dan Sales',
            'description' => 'Lampiran IX.9 — Fungsi Marketing dan Sales.',
            'positions' => [
                [5, 'Asisten Manager Marketing dan Sales'],
                [4, 'Asisten Marketing'],
                [4, 'Asisten Sales'],
                [3, 'Customer Relation'],
                [3, 'Social Media Specialist'],
                [3, 'Creative Design'],
                [3, 'Pengawas Sales Room'],
                [3, 'Pengawas Sales Event'],
                [3, 'Pengawas Front Office'],
                [1, 'Karyawan Pelaksana Sales Room'],
                [1, 'Karyawan Pelaksana Sales Event'],
                [1, 'Karyawan Pelaksana Front Office'],
            ],
        ],
    ];

    /**
     * Jabatan TAMBAHAN di luar lampiran SK (nomenklatur Unit Agrowisata dari data Excel), per bidang.
     * Setiap jabatan: [kode, level BRM, nama jabatan, keterangan (opsional)].
     * Kodenya ditulis tetap (tidak ikut nomor urut di atas), supaya kode jabatan dari SK tidak bergeser.
     * Menambah jabatan: tulis satu baris di bidangnya dengan kode berikutnya (terakhir: JAB-191).
     *
     * @var array<string, list<array{0: string, 1: int, 2: string, 3?: string}>>
     */
    private const ADDITIONAL_POSITIONS = [
        'ADK' => [
            ['JAB-139', 6, 'Karyawan Administrasi'],
            ['JAB-140', 6, 'Pelaksana Gudang'],
        ],
        'UML' => [
            ['JAB-136', 3, 'Asisten SDM & Umum'],
            ['JAB-142', 4, 'Admin I SDM & Umum'],
            ['JAB-138', 5, 'Penyelia Sistem Manajemen'],
            ['JAB-143', 6, 'Petugas Mess'],
            ['JAB-144', 6, 'Sopir'],
            ['JAB-145', 6, 'Tenaga Pengajar'],
        ],
        'INS' => [
            ['JAB-146', 5, 'Admin Teknik'],
            ['JAB-147', 5, 'Mandor Listrik dan Pembangkit'],
            ['JAB-148', 6, 'Mekanik'],
            ['JAB-149', 6, 'Montir'],
            ['JAB-150', 6, 'Teknisi Alat Pertanian'],
            ['JAB-151', 6, 'Teknisi Listrik'],
        ],
        'OPS' => [
            ['JAB-137', 2, 'Asisten Manager Operasional', 'Membawahi Operasional Wilayah I dan Wilayah II sekaligus.'],
            ['JAB-141', 3, 'Asisten Diperbantukan'],
            ['JAB-152', 4, 'Pengawas'],
            ['JAB-153', 4, 'Pengawas Operasional'],
            ['JAB-154', 5, 'Admin Afdeling'],
            ['JAB-155', 5, 'Admin Operasional'],
            ['JAB-156', 5, 'Mandor Panen Gunting / Manual'],
            ['JAB-157', 5, 'Mandor Pemangkasan'],
            ['JAB-158', 5, 'Penanggung Jawab Unit'],
            ['JAB-159', 6, 'Bongkar Muat'],
            ['JAB-160', 6, 'Juru Timbang Kebun'],
            ['JAB-161', 6, 'Karyawan Operasional'],
            ['JAB-162', 6, 'Karyawan Parkir'],
            ['JAB-163', 6, 'Karyawan Pemandu Wisata'],
            ['JAB-164', 6, 'Karyawan Pemeliharaan'],
            ['JAB-165', 6, 'Karyawan Pemeliharaan Teh Lancuran'],
            ['JAB-166', 6, 'Karyawan TBM'],
            ['JAB-167', 6, 'Pelaksana Cleaning Service'],
            ['JAB-168', 6, 'Pelaksana Laundry'],
            ['JAB-169', 6, 'Pelaksana Panen Dengan Mesin Petik'],
            ['JAB-170', 6, 'Pelaksana Penyiangan Kimiawi'],
            ['JAB-171', 6, 'Petugas Retribusi'],
            ['JAB-172', 6, 'Tenaga Operator & Maintenance'],
            ['JAB-173', 6, 'Tenaga Rescue & Maintenance'],
        ],
        'FNB' => [
            ['JAB-174', 4, 'Admin I Food & Beverage'],
            ['JAB-175', 4, 'Supervisor'],
            ['JAB-176', 5, 'Head Bar'],
            ['JAB-177', 5, 'Head Cook'],
            ['JAB-178', 5, 'Leader'],
            ['JAB-179', 5, 'Penanggung Jawab Food And Beverage'],
            ['JAB-180', 5, 'Penanggung Jawab Resto Cafe'],
            ['JAB-181', 6, 'All In'],
            ['JAB-182', 6, 'Karyawan Pelaksana Catering'],
            ['JAB-183', 6, 'Karyawan Pelaksana Resto Cafe'],
            ['JAB-184', 6, 'Tenaga Kitchen & Laundry'],
        ],
        'MKS' => [
            ['JAB-185', 5, 'Karyawan Pemasaran'],
            ['JAB-186', 5, 'Penangungjawab Resepsionis'],
            ['JAB-187', 6, 'Karyawan Gallery'],
            ['JAB-188', 6, 'Karyawan Pelaksana Bell Boy'],
            ['JAB-189', 6, 'Karyawan Reservasi'],
            ['JAB-190', 6, 'Pelaksana Resepsionis'],
            ['JAB-191', 6, 'Petugas Merchandise'],
        ],
    ];

    /** Bidang lama yang sudah digabung ke bidang Operasional (OPS). */
    private const RETIRED_DIVISION_CODES = ['OPW-1', 'OPW-2'];

    public function run(): void
    {
        DB::transaction(function () {
            $this->seedDecree();

            Position::updateOrCreate(['code' => self::MANAGER['code']], [
                'title' => self::MANAGER['title'],
                'division_id' => null,
                'brm_level' => self::brmLevelOf(6),
                'job_grade' => null,
                'description' => self::MANAGER['description'],
            ]);

            $number = 2;
            $skippedCodes = [];
            foreach (self::DIVISIONS as $data) {
                $division = Division::updateOrCreate(['code' => $data['code']], [
                    'name' => $data['name'],
                    'description' => $data['description'],
                ]);

                $seededTitles = [];
                foreach ($data['positions'] as $position) {
                    $code = sprintf('JAB-%03d', $number++);

                    // Nama sama di bidang yang sama = satu jabatan. Nomornya tetap dipakai supaya kode lain tidak bergeser.
                    if (in_array($position[1], $seededTitles, true)) {
                        $skippedCodes[] = $code;

                        continue;
                    }
                    $seededTitles[] = $position[1];

                    Position::updateOrCreate(['code' => $code], [
                        'title' => $position[1],
                        'division_id' => $division->id,
                        'brm_level' => self::brmLevelOf($position[0]),
                        'job_grade' => null,
                        'description' => $position[2] ?? null,
                    ]);
                }
            }

            foreach (self::ADDITIONAL_POSITIONS as $divisionCode => $positions) {
                $divisionId = Division::where('code', $divisionCode)->value('id');

                foreach ($positions as $position) {
                    Position::updateOrCreate(['code' => $position[0]], [
                        'title' => $position[2],
                        'division_id' => $divisionId,
                        'brm_level' => $position[1],
                        'job_grade' => null,
                        'description' => $position[3] ?? null,
                    ]);
                }
            }

            $this->removeRetired($skippedCodes);
        });
    }

    /**
     * Hapus sisa struktur lama yang sudah digabung (jabatan Wilayah II yang namanya sama dengan Wilayah I,
     * bidang Operasional Wilayah I & II), selama belum dipakai di penugasan / usulan.
     *
     * @param  list<string>  $codes  kode jabatan yang tidak dibuat lagi
     */
    private function removeRetired(array $codes): void
    {
        $usedPositionIds = DB::table('position_assignments')->pluck('position_id')
            ->merge(DB::table('employee_activation_items')->whereNotNull('position_id')->pluck('position_id'))
            ->merge(DB::table('transfer_items')->whereNotNull('position_id')->pluck('position_id'));

        Position::whereIn('code', $codes)->whereNotIn('id', $usedPositionIds)->delete();
        Division::whereIn('code', self::RETIRED_DIVISION_CODES)->doesntHave('positions')->delete();
    }

    /** Strata di bagan SK -> level BRM: Strata 6 = BRM-1 (Manager), Strata 1 = BRM-6 (Pelaksana). */
    public static function brmLevelOf(int $strata): int
    {
        return 7 - $strata;
    }

    /** SK ini menjadi satu-satunya SK struktur organisasi yang berlaku. */
    private function seedDecree(): void
    {
        $decree = OrganizationDecree::updateOrCreate(['decree_number' => self::DECREE_NUMBER], [
            'decree_date' => self::DECREE_DATE,
            'is_active' => true,
            'notes' => 'Perubahan Ketiga atas Keputusan Direksi PTPN VIII Nomor KEP/III.2/907/VI/2022 tentang '
                .'Organisasi dan Tata Kerja PT Perkebunan Nusantara VIII — Lampiran IX Struktur Organisasi Unit Agrowisata.',
        ]);

        OrganizationDecree::whereKeyNot($decree->id)->update(['is_active' => false]);
    }
}
