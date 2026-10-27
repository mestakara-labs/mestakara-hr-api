<?php

namespace App\Enums;

/**
 * Dasar penambahan satu karyawan (employee_activation_items.type).
 *
 *   Penerimaan Baru       karyawan baru hasil rekrutmen            (karyawan Belum Aktif)
 *   Mutasi Masuk          pindahan dari unit lain; unit asal wajib (Belum Aktif atau Nonaktif)
 *   Pengaktifan Kembali   karyawan yang pernah dinonaktifkan       (khusus Nonaktif)
 */
enum ActivationType: string
{
    use HasValues;

    case NewHire = 'Penerimaan Baru';
    case TransferIn = 'Mutasi Masuk';
    case Reactivation = 'Pengaktifan Kembali';

    /**
     * Jenis yang boleh dipilih untuk karyawan dengan status kepegawaian ini.
     *
     * @return list<self>
     */
    public static function allowedFor(string $activeStatus): array
    {
        return $activeStatus === EmployeeActiveStatus::Inactive->value
            ? [self::TransferIn, self::Reactivation]
            : [self::NewHire, self::TransferIn];
    }
}
