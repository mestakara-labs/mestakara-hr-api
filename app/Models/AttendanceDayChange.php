<?php

namespace App\Models;

use App\Enums\AttendanceSource;
use App\Enums\AttendanceWriteOutcome;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu kejadian perubahan hari absensi (tabel attendance_day_changes): Dibuat / Diubah / Dihapus, oleh sumber apa,
 * dan alasannya. Kolom yang berubah ada di detailnya (attendance_day_change_items). Tidak pernah diubah/dihapus.
 * employee_id & date tetap terisi walaupun hari absensinya sudah dikosongkan (attendance_day_id jadi NULL).
 */
#[Fillable(['attendance_day_id', 'employee_id', 'date', 'action', 'source', 'import_batch_id', 'reason', 'changed_by'])]
class AttendanceDayChange extends Model
{
    use AppendOnly, HasUuids;

    /** Tabel ini hanya punya created_at. */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'action' => AttendanceWriteOutcome::class,
            'source' => AttendanceSource::class,
        ];
    }

    public function attendanceDay(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AttendanceDayChangeItem::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
