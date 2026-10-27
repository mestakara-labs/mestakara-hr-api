<?php

namespace App\Enums;

/** Jenis kejadian di jejak perubahan lembur (overtime_entry_changes.action). */
enum OvertimeChangeAction: string
{
    use HasValues;

    case Created = 'Dibuat';
    case Updated = 'Diubah';
    case Deleted = 'Dihapus';
    /** Dibuat oleh Simpan Rekomendasi Lembur DW */
    case Recommendation = 'Rekomendasi sistem';
}
