<?php

namespace App\Services\Schedule;

use App\Enums\SchedulePeriodStatus;
use App\Enums\ScheduleSource;
use App\Models\Schedule;
use App\Models\SchedulePeriod;
use App\Models\WorkGroupMember;

/**
 * BUAT ULANG JADWAL dari tanggal tertentu — dipakai setelah anggota regu keluar/masuk, pola diubah, dsb.
 *
 * Bandingkan jadwal tersimpan dengan hasil rumus rotasi untuk setiap anggota × tanggal ≥ from_date:
 *
 *   add       anggota aktif, belum ada baris            -> baris baru
 *   remove    ada baris, anggota sudah tidak aktif      -> baris dihapus
 *   change    baris berbeda shift / slot dengan pola    -> baris diubah
 *   conflict  ubahan MANUAL beda pola & keep_manual     -> dipertahankan (hanya dilaporkan)
 *   same      sudah sesuai
 *
 * Periode Terbit: setiap sel yang shift-nya berubah dicatat di riwayat (alasan wajib).
 */
class ScheduleRegenerator
{
    public function __construct(private ScheduleGenerator $generator) {}

    /**
     * Rencana langkah buat ulang (tidak mengubah data).
     *
     * @return list<array{kind: string, entry?: Schedule, member?: WorkGroupMember, date?: string, shift_id?: ?string, slot_code?: string}>
     */
    public function plan(SchedulePeriod $period, string $fromDate, bool $keepManual): array
    {
        $group = $this->generator->loadGroup($period->workGroup);
        $entries = $period->schedules()->whereDate('work_date', '>=', $fromDate)->get();
        $dates = array_filter($this->generator->datesOfMonth($period->month), fn (string $date) => $date >= $fromDate);
        $steps = [];

        foreach ($group->members as $member) {
            foreach ($dates as $date) {
                $expected = $this->generator->expected($group, $member, $date);
                $entry = $entries->first(fn (Schedule $item) => $item->work_group_member_id === $member->id && $item->work_date->toDateString() === $date);

                if ($expected === null) {
                    if ($entry !== null) {
                        $steps[] = ['kind' => 'remove', 'entry' => $entry];
                    }

                    continue;
                }

                if ($entry === null) {
                    $steps[] = ['kind' => 'add', 'member' => $member, 'date' => $date, ...$expected];

                    continue;
                }

                if ($entry->source === ScheduleSource::Manual && $keepManual) {
                    $steps[] = $entry->shift_id === $expected['shift_id']
                        ? ['kind' => 'same']
                        : ['kind' => 'conflict', 'entry' => $entry, ...$expected];

                    continue;
                }

                $isSame = $entry->shift_id === $expected['shift_id']
                    && $entry->slot_code === $expected['slot_code']
                    && $entry->source === ScheduleSource::Generator;
                $steps[] = $isSame ? ['kind' => 'same'] : ['kind' => 'change', 'entry' => $entry, ...$expected];
            }
        }

        // Baris milik anggota yang sudah tidak ada di regu sama sekali.
        $memberIds = $group->members->pluck('id')->all();
        foreach ($entries as $entry) {
            if (! in_array($entry->work_group_member_id, $memberIds, true)) {
                $steps[] = ['kind' => 'remove', 'entry' => $entry];
            }
        }

        return $steps;
    }

    /** Ringkasan rencana untuk pratinjau (RegeneratePreviewResource di front end). */
    public function preview(SchedulePeriod $period, string $fromDate, bool $keepManual): array
    {
        $preview = ['from_date' => $fromDate, 'added' => [], 'removed' => [], 'changed' => [], 'conflicts' => [], 'unchanged_count' => 0];
        $groups = ['add' => 'added', 'remove' => 'removed', 'change' => 'changed', 'conflict' => 'conflicts'];

        foreach ($this->plan($period, $fromDate, $keepManual) as $step) {
            if ($step['kind'] === 'same') {
                $preview['unchanged_count']++;

                continue;
            }

            $preview[$groups[$step['kind']]][] = $step['kind'] === 'add'
                ? ['employee_name' => $step['member']->employee->name, 'date' => $step['date'], 'old_shift_id' => null, 'new_shift_id' => $step['shift_id']]
                : [
                    'employee_name' => $step['entry']->employee->name,
                    'date' => $step['entry']->work_date->toDateString(),
                    'old_shift_id' => $step['entry']->shift_id,
                    'new_shift_id' => $step['kind'] === 'remove' ? null : $step['shift_id'],
                ];
        }

        return $preview;
    }

    /** Terapkan rencana buat ulang. `$reason` dicatat di riwayat sel (periode Terbit). */
    public function apply(SchedulePeriod $period, string $fromDate, bool $keepManual, string $reason): void
    {
        $recordsChanges = $period->status === SchedulePeriodStatus::Published;
        $note = 'Dibuat ulang dari pola'.($reason !== '' ? ": {$reason}" : '');

        foreach ($this->plan($period, $fromDate, $keepManual) as $step) {
            match ($step['kind']) {
                'add' => $this->generator->createRow($period, $step['member'], $step['date'], $step),
                'remove' => $step['entry']->delete(),
                'change' => $this->applyChange($step, $recordsChanges, $note),
                // Ubahan manual dipertahankan; hanya catatan "shift menurut pola" yang diperbarui.
                'conflict' => $step['entry']->update(['generated_shift_id' => $step['shift_id']]),
                default => null,
            };
        }
    }

    /**
     * Berapa sel yang TIDAK sesuai lagi dengan pola & anggota terbaru (perlu ditambah, dihapus, atau diubah;
     * ubahan manual tidak dihitung). Dipakai untuk peringatan "jadwal perlu dibuat ulang".
     */
    public function outdatedCount(SchedulePeriod $period): int
    {
        if (! $period->status->isEditable()) {
            return 0;
        }

        return collect($this->plan($period, "{$period->month}-01", keepManual: true))
            ->whereIn('kind', ['add', 'remove', 'change'])
            ->count();
    }

    private function applyChange(array $step, bool $recordsChanges, string $note): void
    {
        /** @var Schedule $entry */
        $entry = $step['entry'];

        if ($recordsChanges && $entry->shift_id !== $step['shift_id']) {
            $entry->changes()->create([
                'old_shift_id' => $entry->shift_id,
                'new_shift_id' => $step['shift_id'],
                'reason' => $note,
                'changed_by' => auth()->id(),
                'changed_at' => now(),
            ]);
        }

        $entry->update([
            'shift_id' => $step['shift_id'],
            ...$this->generator->plannedTimes($entry->work_date->toDateString(), $step['shift_id']),
            'slot_code' => $step['slot_code'],
            'generated_shift_id' => $step['shift_id'],
            'source' => ScheduleSource::Generator,
        ]);
    }
}
