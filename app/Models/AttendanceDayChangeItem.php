<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Detail jejak perubahan (tabel attendance_day_change_items): satu kolom, nilai lama -> nilai baru (teks).
 */
#[Fillable(['attendance_day_change_id', 'field', 'old_value', 'new_value'])]
class AttendanceDayChangeItem extends Model
{
    use AppendOnly, HasUuids;

    public $timestamps = false;
}
