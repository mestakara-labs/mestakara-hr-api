<?php

namespace App\Http\Resources\Schedule;

use App\Enums\ScheduleSource;
use App\Models\Schedule;
use App\Models\SchedulePeriod;
use App\Services\Schedule\ScheduleGenerator;
use App\Services\Schedule\ScheduleRegenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Periode jadwal, sesuai SchedulePeriodResource di front end (types/api/schedule.ts).
 * Gunakan ->withDetail() untuk detail (SchedulePeriodDetailResource: + dates, entries, vacancies).
 * Controller wajib memuat relasi di RELATIONS.
 *
 * @mixin SchedulePeriod
 */
class SchedulePeriodResource extends JsonResource
{
    public const RELATIONS = ['workGroup', 'generator', 'publisher', 'locker', 'schedules.employee', 'schedules.changes'];

    private bool $withDetail = false;

    /** Sertakan tanggal, semua baris jadwal, dan shift kosong. */
    public function withDetail(): self
    {
        $this->withDetail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $generator = app(ScheduleGenerator::class);
        $entries = $this->schedules->sortBy([['work_date', 'asc'], ['employee_id', 'asc']])->values();
        $vacancies = $generator->vacancies($this->resource, $entries);

        $data = [
            'id' => $this->id,
            'group' => ['id' => $this->workGroup->id, 'name' => $this->workGroup->name],
            /** Bulan "YYYY-MM". */
            'month' => $this->month,
            /** Draf / Terbit / Terkunci. */
            'status' => $this->status->value,
            'generated_at' => $this->generated_at->toIso8601String(),
            /** Nama pembuat, atau "Sistem" kalau dibuat cron. */
            'generated_by' => $this->generator?->name ?? 'Sistem',
            'published_at' => $this->published_at?->toIso8601String(),
            'published_by' => $this->publisher?->name,
            'locked_at' => $this->locked_at?->toIso8601String(),
            'locked_by' => $this->locker?->name,
            /** Jumlah sel yang diubah manual. */
            'manual_count' => $entries->where('source', ScheduleSource::Manual)->count(),
            /** Jumlah hari yang punya shift kekurangan orang. */
            'vacancy_days' => collect($vacancies)->pluck('date')->unique()->count(),
            /** Sel yang tidak sesuai lagi dengan pola & anggota terbaru (> 0 = sebaiknya Buat Ulang). */
            'outdated_count' => app(ScheduleRegenerator::class)->outdatedCount($this->resource),
        ];

        if ($this->withDetail) {
            $data['dates'] = $generator->datesOfMonth($this->month);
            $data['entries'] = $entries->map(fn (Schedule $entry) => self::entry($entry));
            $data['vacancies'] = $vacancies;
        }

        return $data;
    }

    /** Satu baris jadwal (ScheduleEntryResource). Butuh relasi employee & changes. */
    public static function entry(Schedule $entry): array
    {
        return [
            'id' => $entry->id,
            'employee' => ['id' => $entry->employee->id, 'name' => $entry->employee->name],
            'member_id' => $entry->work_group_member_id,
            'work_date' => $entry->work_date->toDateString(),
            /** null = libur. */
            'shift_id' => $entry->shift_id,
            /** Salinan jam, "YYYY-MM-DDTHH:MM". null = libur. */
            'planned_start' => $entry->planned_start?->format('Y-m-d\TH:i'),
            'planned_end' => $entry->planned_end?->format('Y-m-d\TH:i'),
            'slot_code' => $entry->slot_code,
            /** Shift menurut pola (sebelum diubah manual). */
            'generated_shift_id' => $entry->generated_shift_id,
            /** Generator / Manual. */
            'source' => $entry->source->value,
            'change_count' => $entry->changes->count(),
        ];
    }
}
