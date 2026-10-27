<?php

namespace App\Enums;

/** Jenis kelamin (karyawan & anggota batih). */
enum Gender: string
{
    use HasValues;

    case Male = 'Laki-laki';
    case Female = 'Perempuan';
}
