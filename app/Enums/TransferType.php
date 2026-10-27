<?php

namespace App\Enums;

/**
 * Jenis usulan mutasi (transfers.type).
 *
 *   Penempatan Awal          karyawan yang belum punya jabatan aktif; tidak ada jabatan lama
 *   Rotasi / Promosi / Demosi  WAJIB memilih jabatan lama yang diakhiri
 *   Penugasan Sementara      jabatan lama boleh diakhiri, boleh juga merangkap
 */
enum TransferType: string
{
    use HasValues;

    case InitialPlacement = 'Penempatan Awal';
    case Rotation = 'Rotasi';
    case Promotion = 'Promosi';
    case Demotion = 'Demosi';
    case TemporaryAssignment = 'Penugasan Sementara';

    /** Apakah jenis ini WAJIB mengakhiri jabatan lama? (Rotasi, Promosi, Demosi) */
    public function requiresFromAssignment(): bool
    {
        return ! in_array($this, [self::InitialPlacement, self::TemporaryAssignment], true);
    }
}
