<?php

namespace App\Http\Resources\Schedule;

use App\Models\PatternSlot;
use App\Models\WorkPattern;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pola kerja, sesuai WorkPatternResource di front end (types/api/work-pattern.ts).
 * Controller wajib memuat relasi slots.days dan withCount('workGroups').
 *
 * @mixin WorkPattern
 */
class WorkPatternResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            /** Panjang siklus (1–28). Hari ke-1 = Tanggal Mulai Berlaku Jadwal regu. */
            'cycle_days' => $this->cycle_days,
            'description' => $this->description,
            /** Urut sesuai urutan putaran. shift_ids: id shift per hari, null = libur. */
            'slots' => $this->slots->map(fn (PatternSlot $slot) => [
                'id' => $slot->id,
                'code' => $slot->code,
                'name' => $slot->name,
                'rotates' => $slot->rotates,
                'shift_ids' => $slot->shiftIds(),
            ])->values(),
            /** Berapa regu kerja yang memakai pola ini. */
            'group_count' => (int) $this->work_groups_count,
        ];
    }
}
