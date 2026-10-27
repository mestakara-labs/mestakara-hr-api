<?php

namespace App\Models;

use App\Enums\AttendanceCode;
use App\Enums\AttendanceSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Absensi SATU karyawan pada SATU tanggal (tabel attendance_days) = dasar perhitungan upah.
 * Hanya kode K yang dibayar. Jangan tulis langsung: pakai App\Services\Attendance\AttendanceDayService
 * (aturan prioritas sumber, penguncian periode, dan jejak perubahan).
 */
#[Fillable([
    'employee_id', 'date', 'status_code', 'clock_in_at', 'clock_out_at', 'worked_minutes', 'late_minutes',
    'early_leave_minutes', 'schedule_id', 'source', 'import_batch_id', 'notes', 'updated_by',
])]
class AttendanceDay extends Model
{
    use HasUuids;

    /** Isian hari absensi yang diisi sumber data (dan dicatat di jejak perubahan). */
    public const DATA_FIELDS = [
        'status_code', 'clock_in_at', 'clock_out_at', 'worked_minutes', 'late_minutes',
        'early_leave_minutes', 'schedule_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status_code' => AttendanceCode::class,
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'worked_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'source' => AttendanceSource::class,
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

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(AttendanceImportBatch::class, 'import_batch_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Jejak perubahan hari ini. */
    public function changes(): HasMany
    {
        return $this->hasMany(AttendanceDayChange::class);
    }
}
