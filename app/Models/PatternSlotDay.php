<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Shift satu slot pada hari ke-n siklus (tabel pattern_slot_days). shift_id NULL = libur.
 */
#[Fillable(['pattern_slot_id', 'day_index', 'shift_id'])]
class PatternSlotDay extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'day_index' => 'integer',
        ];
    }
}
