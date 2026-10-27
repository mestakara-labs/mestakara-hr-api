<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu catatan ubahan sel jadwal (tabel schedule_changes): shift lama -> baru (NULL = libur).
 */
#[Fillable(['schedule_id', 'old_shift_id', 'new_shift_id', 'reason', 'changed_by', 'changed_at'])]
class ScheduleChange extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
