<?php

namespace App\Enums;

/**
 * Status jabatan sebuah penugasan (position_assignments.assignment_status).
 * Satu karyawan hanya boleh punya SATU penugasan Definitif yang aktif; status lain boleh merangkap.
 */
enum AssignmentStatus: string
{
    use HasValues;

    case Definitive = 'Definitif';
    case Assignment = 'Penugasan';
    case Acting = 'Pj';
    case ActingOfficer = 'Plt';
    case DailyOfficer = 'Plh';
    case Concurrent = 'Ptr';
}
