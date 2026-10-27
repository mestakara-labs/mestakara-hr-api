<?php

namespace App\Enums;

/**
 * Cakupan dokumen usulan Penambahan / Penonaktifan.
 *
 *   Kolektif   satu dokumen untuk semua karyawan (disimpan di usulan)
 *   Per Orang  dokumen sendiri untuk setiap karyawan (disimpan di item)
 */
enum DocumentScope: string
{
    use HasValues;

    case Collective = 'Kolektif';
    case PerPerson = 'Per Orang';
}
