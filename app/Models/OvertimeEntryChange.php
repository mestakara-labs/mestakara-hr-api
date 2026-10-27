<?php

namespace App\Models;

use App\Enums\OvertimeChangeAction;
use App\Enums\OvertimeSource;
use App\Enums\OvertimeType;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu kejadian perubahan lembur (tabel overtime_entry_changes): Dibuat / Diubah / Dihapus / Rekomendasi sistem,
 * sumber, alasan, dan siapa. Isian yang berubah ada di detailnya (overtime_entry_change_items).
 * Tidak pernah diubah/dihapus; employee_id, date, & overtime_type tetap terisi walaupun barisnya sudah dihapus.
 */
#[Fillable(['overtime_entry_id', 'employee_id', 'date', 'overtime_type', 'action', 'source', 'reason', 'changed_by'])]
class OvertimeEntryChange extends Model
{
    use AppendOnly, HasUuids;

    /** Tabel ini hanya punya created_at. */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'overtime_type' => OvertimeType::class,
            'action' => OvertimeChangeAction::class,
            'source' => OvertimeSource::class,
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OvertimeEntryChangeItem::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
