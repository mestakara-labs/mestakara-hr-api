<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu file unggahan (tabel files): foto, ijazah, SK, dokumen dasar, petikan.
 */
#[Fillable(['original_name', 'mime_type', 'size_bytes', 'disk', 'path', 'uploaded_by'])]
class File extends Model
{
    use HasUuids;

    /** Tabel files hanya punya created_at (file tidak pernah diubah, hanya diganti). */
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
