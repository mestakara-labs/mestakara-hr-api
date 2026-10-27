<?php

namespace App\Models;

use App\Enums\AttendanceEventType;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LOG MENTAH satu scan absensi dari aplikasi mobile (tabel attendance_events).
 * Tidak pernah diubah/dihapus. Hasil olahannya masuk ke attendance_days (sumber "mobile").
 */
#[Fillable([
    'employee_id', 'event_type', 'occurred_at', 'device_time', 'latitude', 'longitude', 'accuracy_m',
    'photo_path', 'device_id', 'schedule_id', 'is_valid', 'invalid_reason',
])]
class AttendanceEvent extends Model
{
    use AppendOnly, HasUuids;

    /** Tabel ini hanya punya created_at. */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'event_type' => AttendanceEventType::class,
            'occurred_at' => 'datetime',
            'device_time' => 'datetime',
            'is_valid' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }
}
