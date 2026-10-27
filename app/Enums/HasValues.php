<?php

namespace App\Enums;

/**
 * Tambahan untuk enum berisi teks: ambil semua nilainya sekaligus.
 *
 *   Gender::values()  ->  ['Laki-laki', 'Perempuan']
 */
trait HasValues
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
