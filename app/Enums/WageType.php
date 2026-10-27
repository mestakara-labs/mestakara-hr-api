<?php

namespace App\Enums;

/** Jenis upah Karyawan Casual & Daily Worker (employee_wage_schemes.wage_type). */
enum WageType: string
{
    use HasValues;

    /** Dibayar per hari kerja (kode K) */
    case Daily = 'Harian';
    /** Dibayar tetap sebulan */
    case FlatMonthly = 'Flat Bulanan';
}
