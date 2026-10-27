<?php

namespace App\Enums;

/**
 * Mode rotasi regu kerja (work_groups.rotation_mode).
 *
 *   Tetap     anggota selalu di slot awalnya
 *   Bergilir  anggota maju ke slot berikutnya setiap rotate_every_days
 */
enum RotationMode: string
{
    use HasValues;

    case Fixed = 'Tetap';
    case Rotating = 'Bergilir';

    /** Batas "rotasi tiap N hari". */
    public const MAX_ROTATE_EVERY_DAYS = 56;
}
