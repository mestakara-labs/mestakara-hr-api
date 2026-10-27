<?php

namespace App\Models;

use App\Enums\AttendanceImportType;
use App\Enums\ImportBatchStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu unggahan Excel absensi / lembur (tabel attendance_import_batches) beserta ringkasan hasilnya.
 */
#[Fillable([
    'period', 'import_type', 'file_name', 'uploaded_by',
    'total_rows', 'created_count', 'updated_count', 'deleted_count', 'skipped_count', 'conflict_count', 'status',
])]
class AttendanceImportBatch extends Model
{
    use HasUuids;

    /** Tabel ini hanya punya created_at. */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'import_type' => AttendanceImportType::class,
            'status' => ImportBatchStatus::class,
        ];
    }

    /** Lembur yang masih tersimpan dari unggahan ini. */
    public function overtimeEntries(): HasMany
    {
        return $this->hasMany(OvertimeEntry::class, 'import_batch_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
