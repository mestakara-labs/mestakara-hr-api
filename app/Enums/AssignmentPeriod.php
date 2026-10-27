<?php

namespace App\Enums;

/**
 * Masa tugas sebuah penugasan, DIHITUNG dari TMT mulai/selesai terhadap hari ini:
 *
 *   Akan Datang  TMT mulai belum tiba
 *   Aktif        sudah dimulai dan belum selesai
 *   Selesai      TMT selesai sudah lewat
 *
 * Aturan yang sama dipakai untuk keanggotaan regu kerja.
 */
enum AssignmentPeriod: string
{
    case Upcoming = 'Akan Datang';
    case Active = 'Aktif';
    case Finished = 'Selesai';
}
