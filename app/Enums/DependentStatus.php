<?php

namespace App\Enums;

/** Status tanggungan anggota batih (employee_family_members.dependent_status). */
enum DependentStatus: string
{
    use HasValues;

    case Dependent = 'Ditanggung';
    case NotDependent = 'Tidak ditanggung';
}
