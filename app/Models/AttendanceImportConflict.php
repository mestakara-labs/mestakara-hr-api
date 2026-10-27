<?php

namespace App\Models;

use App\Enums\AttendanceSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu KONFLIK unggahan Excel (tabel attendance_import_conflicts, detail attendance_import_batches):
 * tanggal yang tidak ditimpa karena tersimpan dari sumber yang lebih kuat (mobile / manual) dan isinya berbeda.
 * Nilai berupa kode absensi (daftar hadir) atau rentang jam "17:00–19:00" (lembur).
 */
#[Fillable(['import_batch_id', 'employee_id', 'date', 'current_value', 'current_source', 'file_value'])]
class AttendanceImportConflict extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'current_source' => AttendanceSource::class,
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AttendanceImportBatch::class, 'import_batch_id');
    }
}
