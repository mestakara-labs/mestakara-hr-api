<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu slot (posisi) di pola kerja (tabel pattern_slots), mis. "A — Posisi 1".
 * `rotates` false = slot TETAP: anggotanya tidak ikut bergilir walaupun regunya Bergilir.
 */
#[Fillable(['work_pattern_id', 'code', 'name', 'sort_order', 'rotates'])]
class PatternSlot extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'rotates' => 'boolean',
        ];
    }

    public function workPattern(): BelongsTo
    {
        return $this->belongsTo(WorkPattern::class);
    }

    /** Isi per hari siklus, urut dari hari ke-1. */
    public function days(): HasMany
    {
        return $this->hasMany(PatternSlotDay::class)->orderBy('day_index');
    }

    /**
     * Shift per hari siklus, urut dari hari ke-1: [id shift | null (libur), ...].
     * Butuh relasi days.
     *
     * @return list<string|null>
     */
    public function shiftIds(): array
    {
        return $this->days->pluck('shift_id')->all();
    }
}
