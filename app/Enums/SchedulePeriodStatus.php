<?php

namespace App\Enums;

/**
 * Status periode jadwal (schedule_periods.status).
 *
 *   Proyeksi ──(Buat Jadwal)──> Draf ──(Terbitkan)──> Terbit ──(Kunci)──> Terkunci
 *   dihitung, tidak disimpan    bisa diubah bebas     ubahan wajib alasan    tidak bisa diubah
 */
enum SchedulePeriodStatus: string
{
    use HasValues;

    case Draft = 'Draf';
    case Published = 'Terbit';
    case Locked = 'Terkunci';

    /** Jadwal masih boleh diubah / dibuat ulang? (Draf & Terbit) */
    public function isEditable(): bool
    {
        return $this !== self::Locked;
    }

    /** Ubahan wajib disertai alasan? (setelah Terbit, karena karyawan sudah melihatnya) */
    public function requiresChangeReason(): bool
    {
        return $this === self::Published;
    }
}
