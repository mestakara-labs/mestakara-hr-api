<?php

namespace App\Services\Schedule;

use App\Enums\ScheduleSource;
use App\Models\Schedule;
use App\Models\SchedulePeriod;
use App\Models\Shift;
use App\Models\WorkGroup;
use App\Models\WorkGroupMember;
use Illuminate\Support\Collection;

/**
 * PEMBUAT JADWAL: mengubah rumus rotasi (Rotation) menjadi baris jadwal harian.
 *
 *   untuk setiap anggota × setiap tanggal di bulan periode:
 *     Rotation::shiftOn(regu, pola, anggota, tanggal)  -> slot & shift
 *     jam shift DISALIN ke planned_start / planned_end (jadwal lama tidak berubah kalau master shift diubah)
 *
 * Dipakai tombol "Buat Jadwal", cron tanggal 20 (bulan depan), dan "Buat Ulang dari Tanggal".
 */
class ScheduleGenerator
{
    /** @var Collection<string, Shift>|null semua shift (key = id), dimuat sekali */
    private ?Collection $shifts = null;

    /** Buat SEMUA baris jadwal sebuah periode (yang baru dibuat). */
    public function fill(SchedulePeriod $period): void
    {
        $group = $this->loadGroup($period->workGroup);

        foreach ($group->members as $member) {
            foreach ($this->datesOfMonth($period->month) as $date) {
                $expected = $this->expected($group, $member, $date);
                if ($expected !== null) {
                    $this->createRow($period, $member, $date, $expected);
                }
            }
        }
    }

    /**
     * Shift menurut pola untuk satu anggota pada satu tanggal: ['slot_code' => ..., 'shift_id' => ?string].
     * null = anggota tidak aktif pada tanggal itu.
     */
    public function expected(WorkGroup $group, WorkGroupMember $member, string $date): ?array
    {
        $pattern = $group->workPattern;
        $result = Rotation::shiftOn($group, $pattern->slots, $pattern->cycle_days, $member, $date);

        return $result === null ? null : ['slot_code' => $result['slot']->code, 'shift_id' => $result['shift_id']];
    }

    /** Tambah satu baris jadwal hasil pola. */
    public function createRow(SchedulePeriod $period, WorkGroupMember $member, string $date, array $expected): Schedule
    {
        return $period->schedules()->create([
            'work_group_member_id' => $member->id,
            'employee_id' => $member->employee_id,
            'work_date' => $date,
            'shift_id' => $expected['shift_id'],
            ...$this->plannedTimes($date, $expected['shift_id']),
            'slot_code' => $expected['slot_code'],
            'generated_shift_id' => $expected['shift_id'],
            'source' => ScheduleSource::Generator,
        ]);
    }

    /**
     * Jam rencana sebuah shift pada tanggal kerja (salinan jam shift).
     *   Pagi 07:00–12:00 tgl 14  -> 2026-10-14 07:00 s.d. 2026-10-14 12:00
     *   Malam 22:00–06:00 tgl 14 -> 2026-10-14 22:00 s.d. 2026-10-15 06:00 (selesai keesokan harinya)
     * Libur (tanpa shift) -> kosong.
     */
    public function plannedTimes(string $date, ?string $shiftId): array
    {
        $shift = $shiftId ? $this->shifts()->get($shiftId) : null;
        if ($shift === null) {
            return ['planned_start' => null, 'planned_end' => null];
        }

        $endDate = $shift->crossesMidnight() ? gmdate('Y-m-d', strtotime("{$date} UTC") + 86400) : $date;

        return [
            'planned_start' => "{$date} {$shift->startTime()}:00",
            'planned_end' => "{$endDate} {$shift->endTime()}:00",
        ];
    }

    /**
     * Shift yang KURANG orang pada setiap tanggal periode: [['date', 'shift_id', 'missing'], ...].
     *   kebutuhan = jumlah slot pola yang berisi shift itu pada hari siklus tersebut
     *   terisi    = jumlah baris jadwal dengan shift itu pada tanggal tersebut
     * Sebelum Tanggal Mulai Berlaku Jadwal, regu belum berjalan -> tidak ada shift kosong.
     *
     * @param  Collection<int, Schedule>  $entries  baris jadwal periode
     */
    public function vacancies(SchedulePeriod $period, Collection $entries): array
    {
        $group = $this->loadGroup($period->workGroup);
        $pattern = $group->workPattern;
        $coverage = $pattern->coveragePerDay();
        $filled = $entries->whereNotNull('shift_id')->countBy(fn (Schedule $entry) => $entry->work_date->toDateString().'|'.$entry->shift_id);

        $vacancies = [];
        foreach ($this->datesOfMonth($period->month) as $date) {
            if ($date < $group->anchor_date->toDateString()) {
                continue;
            }

            foreach ($coverage[Rotation::dayIndex($group, $pattern->cycle_days, $date)] as $shiftId => $needed) {
                $missing = $needed - ($filled["{$date}|{$shiftId}"] ?? 0);
                if ($missing > 0) {
                    $vacancies[] = ['date' => $date, 'shift_id' => $shiftId, 'missing' => $missing];
                }
            }
        }

        return $vacancies;
    }

    /** Semua tanggal dalam bulan "YYYY-MM". */
    public function datesOfMonth(string $month): array
    {
        return Rotation::datesBetween("{$month}-01", gmdate('Y-m-t', strtotime("{$month}-01 UTC")));
    }

    /** Muat relasi yang dibutuhkan rumus (pola + slot + isi hari, anggota). */
    public function loadGroup(WorkGroup $group): WorkGroup
    {
        return $group->loadMissing(['workPattern.slots.days', 'members']);
    }

    /** @return Collection<string, Shift> */
    private function shifts(): Collection
    {
        return $this->shifts ??= Shift::all()->keyBy('id');
    }
}
