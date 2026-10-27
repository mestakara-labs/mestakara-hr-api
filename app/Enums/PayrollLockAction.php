<?php

namespace App\Enums;

/** Kejadian di riwayat kunci periode upah (payroll_period_events.action). */
enum PayrollLockAction: string
{
    use HasValues;

    case Lock = 'kunci';
    case Unlock = 'buka';
}
