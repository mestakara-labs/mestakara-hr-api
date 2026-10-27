<?php

namespace App\Enums;

/** Status perkawinan karyawan (employees.marital_status). */
enum MaritalStatus: string
{
    use HasValues;

    case Single = 'Belum Kawin';
    case Married = 'Kawin';
    case Divorced = 'Cerai Hidup';
    case Widowed = 'Cerai Mati';
}
