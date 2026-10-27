<?php

namespace App\Enums;

/** Agama karyawan (employees.religion). */
enum Religion: string
{
    use HasValues;

    case Islam = 'Islam';
    case Protestant = 'Kristen';
    case Catholic = 'Katolik';
    case Hindu = 'Hindu';
    case Buddhist = 'Buddha';
    case Confucian = 'Konghucu';
}
