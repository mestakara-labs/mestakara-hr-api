<?php

namespace App\Enums;

/** Sisi satu baris detail riwayat atasan (supervisor_change_details.side). */
enum SupervisorChangeSide: string
{
    case Before = 'Sebelum';
    case After = 'Sesudah';
}
