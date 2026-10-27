<?php

namespace App\Enums;

/** Hubungan anggota batih dengan karyawan (employee_family_members.relationship). */
enum FamilyRelationship: string
{
    use HasValues;

    case Husband = 'Suami';
    case Wife = 'Istri';
    case Child = 'Anak';
}
