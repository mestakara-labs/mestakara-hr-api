<?php

namespace App\Enums;

/**
 * Hasil menulis satu hari absensi lewat AttendanceDayService.
 * Dibuat, Diubah, & Dihapus juga disimpan sebagai jenis jejak (attendance_day_changes.action).
 */
enum AttendanceWriteOutcome: string
{
    use HasValues;

    case Created = 'Dibuat';
    case Updated = 'Diubah';
    /** Hari dikosongkan (baris absensi dihapus) */
    case Deleted = 'Dihapus';
    /** Isinya sama dengan yang tersimpan */
    case Unchanged = 'Tidak Berubah';
    /** Tanggal itu bersumber lebih kuat dan isinya berbeda: TIDAK ditimpa */
    case Conflict = 'Konflik';
    /** Periode upahnya sudah dikunci */
    case Locked = 'Dikunci';
}
