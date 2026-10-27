<?php

namespace Database\Seeders;

use App\Enums\SignatorySource;
use App\Enums\SignatureOwnerType;
use App\Models\SignatureSetting;
use App\Services\Payroll\SignatureService;
use Illuminate\Database\Seeder;

/**
 * Penanda tangan UMUM bawaan untuk Daftar Upah & SPKL (tempat "Gunung Mas"), urut seperti di dokumen.
 * Setiap bagian berisi satu orang manual; admin bisa menggantinya dengan karyawan.
 *
 * Kalau susunan Umum sudah ada, tidak diubah (supaya isian admin tidak tertimpa).
 */
class SignatureSettingSeeder extends Seeder
{
    /** [label bagian, jabatan, nama] per dokumen. */
    public const PAYROLL_SECTIONS = [
        ['Disetujui', 'Manajer', 'M. TAUFIK SIREGAR SE'],
        ['Dibenarkan', 'Asisten Tata Usaha', 'TAUFIK HIDAYAT'],
        ['Diperiksa', 'Admin Umum', 'IYUS KOSWARA'],
    ];

    public const SPKL_SECTIONS = [
        ['Diperiksa', 'Admin I Umum', 'IYUS KOSWARA'],
        ['Disetujui', 'Asisten Manajer Umum & Legal', 'ASEP ZAENAL M SP'],
        ['Dibenarkan', 'Asisten Tata Usaha', 'TAUFIK HIDAYAT'],
    ];

    public function run(SignatureService $signatures): void
    {
        if (SignatureSetting::query()->where('owner_type', SignatureOwnerType::General)->exists()) {
            return;
        }

        $signatures->save(
            SignatureOwnerType::General,
            null,
            SignatureService::DEFAULT_PLACE,
            $this->sections(self::PAYROLL_SECTIONS),
            $this->sections(self::SPKL_SECTIONS),
        );
    }

    /** Ubah [label, jabatan, nama] menjadi bentuk susunan SignatureService. */
    private function sections(array $rows): array
    {
        return array_map(fn (array $row) => [
            'label' => $row[0],
            'signers' => [['source' => SignatorySource::Manual->value, 'position' => $row[1], 'name' => $row[2]]],
        ], $rows);
    }
}
