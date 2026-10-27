<?php

namespace App\Enums;

/**
 * Status periode upah (payroll_periods.status).
 * Periode Dikunci: absensi harian & lembur pada bulan itu tidak boleh berubah dari sumber mana pun.
 */
enum PayrollPeriodStatus: string
{
    use HasValues;

    case Draft = 'draf';
    case Locked = 'dikunci';
}
