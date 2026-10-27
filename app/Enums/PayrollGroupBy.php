<?php

namespace App\Enums;

/** Pengelompokan daftar upah (?group_by=). */
enum PayrollGroupBy: string
{
    use HasValues;

    /** Kelompok Daftar Upah dari skema upah */
    case PayrollGroup = 'payroll_group';
    /** Bidang dari jabatan utama */
    case Division = 'division';
}
