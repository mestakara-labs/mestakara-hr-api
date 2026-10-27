<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pola kerja (tabel work_patterns) = tabel SLOT × HARI SIKLUS.
 *
 *   Pola "Tiga Shift" (siklus 7 hari)
 *            1   2   3   4   5   6   7
 *   Slot A   P   P   P   P   L   P   P
 *   Slot B   S   S   S   L   S   S   S
 *
 * Hari ke-1 = Tanggal Mulai Berlaku Jadwal regu yang memakai pola ini.
 */
#[Fillable(['name', 'cycle_days', 'description'])]
class WorkPattern extends Model
{
    use HasUuids;

    /** Batas panjang siklus. */
    public const MIN_CYCLE_DAYS = 1;

    public const MAX_CYCLE_DAYS = 28;

    protected function casts(): array
    {
        return [
            'cycle_days' => 'integer',
        ];
    }

    /** Slot urut sesuai urutan putaran. */
    public function slots(): HasMany
    {
        return $this->hasMany(PatternSlot::class)->orderBy('sort_order');
    }

    public function workGroups(): HasMany
    {
        return $this->hasMany(WorkGroup::class);
    }

    /**
     * Jumlah slot per shift di SETIAP hari siklus (kebutuhan orang per shift).
     *   hasil[1] = hari ke-1, mis. ['id-shift-pagi' => 2, 'id-shift-sore' => 1]
     * Butuh relasi slots.days.
     *
     * @return array<int, array<string, int>>
     */
    public function coveragePerDay(): array
    {
        $coverage = [];

        for ($day = 1; $day <= $this->cycle_days; $day++) {
            $coverage[$day] = [];

            foreach ($this->slots as $slot) {
                $shiftId = $slot->shiftIds()[$day - 1] ?? null;
                if ($shiftId !== null) {
                    $coverage[$day][$shiftId] = ($coverage[$day][$shiftId] ?? 0) + 1;
                }
            }
        }

        return $coverage;
    }
}
