<?php

namespace App\Enums;

/** Status satu unggahan Excel absensi (attendance_import_batches.status). */
enum ImportBatchStatus: string
{
    use HasValues;

    case Active = 'aktif';
    /** Sudah diganti unggahan yang lebih baru */
    case Replaced = 'diganti';
}
