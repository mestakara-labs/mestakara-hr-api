<?php

namespace App\Http\Resources\Payroll;

use App\Models\OvertimeSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pengaturan Lembur. Controller wajib memuat relasi updater (OvertimeSetting::current() sudah memuatnya).
 *
 * @mixin OvertimeSetting
 */
class OvertimeSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'hourly_divisor' => $this->hourly_divisor,
            'first_hour_multiplier' => $this->first_hour_multiplier,
            'next_hour_multiplier' => $this->next_hour_multiplier,
            'max_hours_per_day' => $this->max_hours_per_day,
            'max_hours_per_week' => $this->max_hours_per_week,
            'rounding' => $this->rounding,
            /** Format HH:MM. */
            'dw_default_start_time' => substr($this->dw_default_start_time, 0, 5),
            /** null = belum diisi admin. */
            'dw_default_description' => $this->dw_default_description,
            'time_step_minutes' => $this->time_step_minutes,
            'updated_by' => $this->updater?->name,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
