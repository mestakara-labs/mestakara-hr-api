<?php

namespace App\Enums;

/** Jenis hari libur (holidays.type). Hanya penanda di kalender, tidak otomatis meliburkan karyawan. */
enum HolidayType: string
{
    use HasValues;

    case National = 'Libur Nasional';
    case CollectiveLeave = 'Cuti Bersama';
    case Company = 'Libur Perusahaan';
}
