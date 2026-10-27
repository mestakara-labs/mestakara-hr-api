<?php

namespace App\Enums;

/** Jenis scan absensi dari aplikasi mobile (attendance_events.event_type). */
enum AttendanceEventType: string
{
    use HasValues;

    case ClockIn = 'clock_in';
    case ClockOut = 'clock_out';
}
