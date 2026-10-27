<?php

namespace App\Models;

use App\Enums\PayrollLockAction;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat kunci / buka kunci satu periode upah (tabel payroll_period_events). Tidak pernah diubah/dihapus.
 */
#[Fillable(['period', 'action', 'reason', 'created_by'])]
class PayrollPeriodEvent extends Model
{
    use AppendOnly, HasUuids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'action' => PayrollLockAction::class,
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
