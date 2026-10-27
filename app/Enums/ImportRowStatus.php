<?php

namespace App\Enums;

/** Hasil pemeriksaan satu baris file impor (pratinjau impor skema upah). */
enum ImportRowStatus: string
{
    use HasValues;

    case Changed = 'berubah';
    case Unchanged = 'tidak berubah';
    case Invalid = 'salah';
}
