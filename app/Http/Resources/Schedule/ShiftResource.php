<?php

namespace App\Http\Resources\Schedule;

use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shift, sesuai ShiftResource di front end (types/api/shift.ts).
 *
 * @mixin Shift
 */
class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $duration = $this->durationMinutes();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            /** "HH:MM" */
            'start_time' => $this->startTime(),
            /** "HH:MM" */
            'end_time' => $this->endTime(),
            'break_minutes' => $this->break_minutes,
            'late_tolerance_minutes' => $this->late_tolerance_minutes,
            'checkin_window_before_minutes' => $this->checkin_window_before_minutes,
            'checkout_window_after_minutes' => $this->checkout_window_after_minutes,
            'color' => $this->color,
            'description' => $this->description,
            /** true = selesai keesokan harinya. */
            'crosses_midnight' => $this->crossesMidnight(),
            /** Lama shift dalam menit, termasuk istirahat. */
            'duration_minutes' => $duration,
            /** Jam kerja bersih dalam menit. */
            'work_minutes' => $duration - $this->break_minutes,
            /** Berapa pola kerja yang memakai shift ini. */
            'pattern_count' => $this->patternCount(),
        ];
    }
}
