<?php

namespace App\Models;

use App\Enums\OvertimeSource;
use App\Enums\OvertimeType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris LEMBUR (tabel overtime_entries): satu karyawan, satu tanggal, satu rentang jam.
 * Jam selesai ≤ jam mulai = lewat tengah malam. Jam dibayar & rupiahnya dihitung saat perhitungan upah.
 *
 * Lembur pada periode upah yang sudah DIKUNCI tidak bisa ditambah, diubah, atau dihapus (409).
 */
#[Fillable([
    'employee_id', 'date', 'start_time', 'end_time', 'duration_minutes', 'overtime_type', 'description',
    'source', 'import_batch_id', 'created_by',
])]
class OvertimeEntry extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::saving(function (OvertimeEntry $entry) {
            PayrollPeriod::ensureNotLockedOn($entry->date);
            if ($entry->isDirty('date') && $entry->getOriginal('date') !== null) {
                PayrollPeriod::ensureNotLockedOn($entry->getOriginal('date'));
            }
        });

        static::deleting(fn (OvertimeEntry $entry) => PayrollPeriod::ensureNotLockedOn($entry->date));
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'duration_minutes' => 'integer',
            'overtime_type' => OvertimeType::class,
            'source' => OvertimeSource::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(AttendanceImportBatch::class, 'import_batch_id');
    }

    /** Durasi lembur (menit) dari jam mulai & selesai "HH:MM"; selesai ≤ mulai = lewat tengah malam. */
    public static function minutesBetween(string $startTime, string $endTime): int
    {
        $start = (int) substr($startTime, 0, 2) * 60 + (int) substr($startTime, 3, 2);
        $end = (int) substr($endTime, 0, 2) * 60 + (int) substr($endTime, 3, 2);

        return $end > $start ? $end - $start : $end + 24 * 60 - $start;
    }
}
