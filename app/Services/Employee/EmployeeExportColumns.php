<?php

namespace App\Services\Employee;

/**
 * DAFTAR KOLOM file ekspor Data Karyawan (sama dengan format "Database Karyawan - Unit Agrowisata").
 *
 * Satu baris = satu kolom Excel:
 *
 *   self::column('E', 'NIK REG', width: 26, green: true, center: true, format: self::TEXT)
 *                 │    │           │          │            │             └ format isi: TEXT ("@") / DATE ("dd/mm/yyyy")
 *                 │    │           │          │            └ isi data rata tengah (kolom format DATE selalu rata tengah)
 *                 │    │           │          └ header berlatar hijau (bawaan: biru)
 *                 │    │           └ lebar kolom (bawaan: DEFAULT_WIDTH)
 *                 │    └ teks header baris 1
 *                 └ huruf kolom
 *
 * Teks header baris 2 ditulis sebagai argumen ketiga, mis. self::column('AD', 'Pendidikan', 'Pendidikan Terakhir').
 * Sel header DIGABUNG otomatis (lihat EmployeeExportWorkbook::mergeHeaders):
 *   - hanya baris 1 terisi            -> digabung ke bawah (A1:A2)
 *   - baris 1 & 2 terisi              -> judul kelompok, digabung ke kanan sampai kolom yang baris 1-nya kosong
 *                                        (AD1:AE1 "Pendidikan" membawahi "Pendidikan Terakhir" & "Jurusan")
 *
 * ISI tiap kolom diatur di EmployeeExportService::row(). Kolom yang tidak diisi di sana dibiarkan kosong.
 * Mengubah kolom: ubah di sini (format) dan di EmployeeExportService::row() (isi).
 */
class EmployeeExportColumns
{
    public const DEFAULT_WIDTH = 20.77734375;

    /** Format isi: teks apa adanya (angka 0 di depan tidak hilang). */
    public const TEXT = '@';

    /** Format isi: tanggal. */
    public const DATE = 'dd/mm/yyyy;@';

    /**
     * Semua kolom, urut dari A sampai DD.
     *
     * @return list<array{letter: string, top: ?string, bottom: ?string, width: float, hidden: bool, green: bool, center: bool, format: ?string}>
     */
    public static function all(): array
    {
        return [
            self::column('A', 'NO', center: true),
            self::column('B', 'REGIONAL', center: true),
            self::column('C', 'KOMODITI', center: true),
            self::column('D', 'KSO/NON KSO', center: true),
            self::column('E', 'NIK REG', width: 26, green: true, center: true, format: self::TEXT),
            self::column('F', 'NIK SAP', center: true, format: self::TEXT),
            self::column('G', 'Nama Karyawan', width: 38.109375),
            self::column('H', 'Gelar Depan', hidden: true),
            self::column('I', 'Gelar Belakang', hidden: true),
            self::column('J', 'AFD', hidden: true, green: true),
            self::column('K', 'SUB BAGIAN', width: 32.33203125, hidden: true, green: true),
            self::column('L', 'Bagian', width: 29.77734375, hidden: true, green: true),
            self::column('M', 'Tempat Lahir', hidden: true, center: true),
            self::column('N', 'Tanggal Lahir', hidden: true, center: true, format: self::DATE),
            self::column('O', 'Usia', hidden: true),
            self::column('P', 'Jenis Kelamin', hidden: true, center: true),
            self::column('Q', 'Posisi/Jabatan', width: 54.88671875),
            self::column('R', 'Job Group'),
            self::column('S', 'Job Func'),
            self::column('T', 'Job'),
            self::column('U', 'Status', center: true),
            self::column('V', 'Penugasan Ke-'),
            self::column('W', 'Personnel Area'),
            self::column('X', 'Pers. Sub Area'),
            self::column('Y', 'Employee Group'),
            self::column('Z', 'Employee Subgroup'),
            self::column('AA', 'Person Grade'),
            self::column('AB', 'Gol PHDP'),
            self::column('AC', 'Level BOD'),
            self::column('AD', 'Pendidikan', 'Pendidikan Terakhir'),
            self::column('AE', null, 'Jurusan'),
            self::column('AF', 'Date Spec', 'Pengangkatan', format: self::DATE),
            self::column('AG', null, 'Pengangkatan Karpim', format: self::DATE),
            self::column('AH', null, 'MBT', format: self::DATE),
            self::column('AI', null, 'Pensiun', format: self::DATE),
            self::column('AJ', null, 'Tanggal Acuan Perhitungan Masa Kerja', format: self::DATE),
            self::column('AK', null, 'Tanggal Acuan Cuti Tahunan', format: self::DATE),
            self::column('AL', null, 'Tanggal Acuan Cuti Panjang', format: self::DATE),
            self::column('AM', null, 'Tanggal Acuan Jubilium', format: self::DATE),
            self::column('AN', null, 'Tanggal Acuan SHT', format: self::DATE),
            self::column('AO', 'Asuransi', 'Nomor BPJS TK'),
            self::column('AP', null, 'Nomor BPJS Kesehatan'),
            self::column('AQ', null, 'Nomor Dapenbun'),
            self::column('AR', null, 'Nomor DPLK'),
            self::column('AS', 'Pajak', 'NPWP'),
            self::column('AT', null, 'PTKP'),
            self::column('AU', 'NO KTP', width: 22.44140625, center: true, format: self::TEXT),
            self::column('AV', 'NO KK'),
            self::column('AW', 'Alamat KTP'),
            self::column('AX', 'Alamat Domisili'),
            self::column('AY', 'Alamat Email'),
            self::column('AZ', 'No TLP'),
            self::column('BA', 'Rekening Bank'),
            self::column('BB', 'Nomor Rekening'),
            self::column('BC', 'BATIH', 'Nama Pasangan'),
            self::column('BD', null, 'Tanggal Lahir', format: self::DATE),
            self::column('BE', null, 'NIK KTP', format: self::TEXT),
            self::column('BF', null, 'Nama Anak ke-1'),
            self::column('BG', null, 'Tanggal Lahir', format: self::DATE),
            self::column('BH', null, 'NIK KTP', format: self::TEXT),
            self::column('BI', null, 'Nama Anak ke-2'),
            self::column('BJ', null, 'Tanggal Lahir', format: self::DATE),
            self::column('BK', null, 'NIK KTP', format: self::TEXT),
            self::column('BL', null, 'Nama Anak ke-3'),
            self::column('BM', null, 'Tanggal Lahir', format: self::DATE),
            self::column('BN', null, 'NIK KTP', format: self::TEXT),
            self::column('BO', null, 'Nama Anak ke-4'),
            self::column('BP', null, 'Tanggal Lahir', format: self::DATE),
            self::column('BQ', null, 'NIK KTP', format: self::TEXT),
            self::column('BR', 'Keluarga yang dapat dihubungi saat keadaan darurat', 'Nama'),
            self::column('BS', null, 'Hubungan Keluarga'),
            self::column('BT', null, 'Nomor TLP/HP'),
            self::column('BU', null, 'Alamat'),
            self::column('BV', 'Pendidikan Terakhir'),
            self::column('BW', 'Jurusan Pendidikan Terakhir'),
            self::column('BX', 'Nama Sekolah/Universitas Pendidikan Terakhir'),
            self::column('BY', 'Nama Sekolah (SD)'),
            self::column('BZ', 'Nama Sekolah (SMP)'),
            self::column('CA', 'Nama Sekolah (SMA/SMK/Setara)'),
            self::column('CB', 'Nama Universitas (D1/D2/D3/D4)'),
            self::column('CC', 'Nama Universitas (S1)'),
            self::column('CD', 'Nama Universitas (S2)'),
            self::column('CE', 'Nama Universitas (S3)'),
            self::column('CF', 'Tanggal Masuk Kerja (Awal Bergabung Dengan PTPN)', format: self::DATE),
            self::column('CG', 'Tanggal Masa Percobaan', format: self::DATE),
            self::column('CH', 'Tanggal Pengangkatan Karyawan Tetap', width: 30.77734375, format: self::DATE),
            self::column('CI', 'Tanggal Pengangkatan menjadi Karyawan Pimpinan', width: 30.77734375, format: self::DATE),
            self::column('CJ', 'Jabatan Terakhir (Jabatan Saat Ini)', width: 30.77734375),
            self::column('CK', 'TMT Menduduki Jabatan Terakhir', width: 30.77734375, format: self::DATE),
            self::column('CL', 'Riwayat Jabatan Sebelum Menduduki Jabatan Saat ini (Nama Jabatan sesuai dengan SK)', width: 30.77734375),
            self::column('CM', 'TMT Jabatan Sebelum Menduduki Jabatan Saat Ini', width: 30.77734375, format: self::DATE),
            self::column('CN', 'Lama Menjabat', width: 30.77734375),
            self::column('CO', 'Riwayat  2 (dua) Jabatan Sebelum Menduduki Jabatan Saat ini (Nama Jabatan sesuai dengan SK)', width: 30.77734375),
            self::column('CP', 'TMT  2 (dua) Jabatan Sebelum Menduduki Jabatan Saat Ini', width: 30.77734375, format: self::DATE),
            self::column('CQ', 'Lama Menjabat', width: 30.77734375),
            self::column('CR', 'Riwayat  3 (tiga) Jabatan Sebelum Menduduki Jabatan Saat ini (Nama Jabatan sesuai dengan SK)', width: 30.77734375),
            self::column('CS', 'TMT  3 (tiga) Jabatan Sebelum Menduduki Jabatan Saat Ini', width: 30.77734375, format: self::DATE),
            self::column('CT', 'Lama Menjabat', width: 30.77734375),
            self::column('CU', 'Riwayat  4 (empat) Jabatan Sebelum Menduduki Jabatan Saat ini (Nama Jabatan sesuai dengan SK)', width: 30.77734375),
            self::column('CV', 'TMT  4 (empat) Jabatan Sebelum Menduduki Jabatan Saat Ini', width: 30.77734375, format: self::DATE),
            self::column('CW', 'Lama Menjabat', width: 30.77734375),
            self::column('CX', 'DOKUMEN PENDUKUNG', 'Scan KTP', width: 25.77734375),
            self::column('CY', null, 'Scan KK', width: 25.77734375),
            self::column('CZ', null, 'Scan NPWP', width: 25.77734375),
            self::column('DA', null, 'Scan Ijazah', width: 25.77734375),
            self::column('DB', null, 'Scan SK Pengangkatan', width: 25.77734375),
            self::column('DC', null, 'Scan SK Pengangkatan Karyawan Pimpinan', width: 25.77734375),
            self::column('DD', null, 'CV Karyawan dengan format BUMN', width: 25.77734375),
        ];
    }

    /**
     * Satu kolom (lihat contoh di atas kelas ini).
     *
     * @return array{letter: string, top: ?string, bottom: ?string, width: float, hidden: bool, green: bool, center: bool, format: ?string}
     */
    private static function column(
        string $letter,
        ?string $top,
        ?string $bottom = null,
        float $width = self::DEFAULT_WIDTH,
        bool $hidden = false,
        bool $green = false,
        bool $center = false,
        ?string $format = null,
    ): array {
        return compact('letter', 'top', 'bottom', 'width', 'hidden', 'green', 'center', 'format');
    }
}
