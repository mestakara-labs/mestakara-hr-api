<?php

namespace App\Enums;

/** Sumber satu baris jadwal (schedules.source). */
enum ScheduleSource: string
{
    use HasValues;

    /** Dibuat otomatis dari pola & rotasi */
    case Generator = 'Generator';
    /** Diubah manual (berbeda dari hasil pola) */
    case Manual = 'Manual';
}
