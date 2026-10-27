<?php

namespace App\Enums;

/** Jenis penulisan asli di kamus nomenklatur (nomenclature_aliases.type). */
enum NomenclatureType: string
{
    use HasValues;

    case Position = 'Jabatan';
    case PlacementObject = 'Objek Penempatan';
    case SubUnit = 'Sub Unit';
}
