<?php

namespace App\Services\Schedule;

use App\Enums\RotationMode;
use App\Models\PatternSlot;
use App\Models\WorkGroup;
use App\Models\WorkGroupMember;
use Illuminate\Support\Collection;

/**
 * RUMUS ROTASI — inti pembuatan jadwal. SAMA PERSIS dengan front end (src/lib/schedule-rules.ts).
 * Semua fungsi MURNI: hanya menghitung dari data yang diberikan, tidak membaca / mengubah database.
 *
 * Rumus satu anggota pada satu tanggal:
 *
 *   selisih_hari      = tanggal − anchor_date
 *   putaran_sekarang  = floor(selisih_hari / rotate_every_days)
 *   putaran_bergabung = floor((start_date − anchor_date) / rotate_every_days)
 *   langkah_maju      = putaran_sekarang − putaran_bergabung
 *   index_slot        = (posisi slot awal + langkah_maju) mod jumlah slot bergilir
 *   day_index         = (selisih_hari mod cycle_days) + 1
 *   shift             = pola[slot][day_index]
 *
 * $slots = slot pola regu, URUT sesuai urutan putaran, dengan relasi days (lihat PatternSlot::shiftIds).
 * Tanggal ditulis "YYYY-MM-DD".
 */
class Rotation
{
    /** Hari ke berapa dalam siklus pola pada tanggal ini (1..cycle_days). Sama untuk semua anggota regu. */
    public static function dayIndex(WorkGroup $group, int $cycleDays, string $date): int
    {
        return self::mod(self::daysBetween($group->anchor_date->toDateString(), $date), $cycleDays) + 1;
    }

    /**
     * Slot yang dipegang anggota pada tanggal ini. null = anggota tidak aktif pada tanggal itu.
     *   - mode Tetap, atau slot awalnya tidak ikut bergilir -> selalu slot awal
     *   - mode Bergilir -> maju sebanyak `langkah_maju` di antara slot yang ikut bergilir
     *
     * @param  Collection<int, PatternSlot>  $slots
     */
    public static function slotOn(WorkGroup $group, Collection $slots, WorkGroupMember $member, string $date): ?PatternSlot
    {
        if (! $member->isActiveOn($date)) {
            return null;
        }

        $startSlot = $slots->firstWhere('id', $member->start_slot_id);
        if ($startSlot === null) {
            return null;
        }
        if ($group->rotation_mode !== RotationMode::Rotating || ! $startSlot->rotates) {
            return $startSlot;
        }

        $rotatingSlots = $slots->filter(fn (PatternSlot $slot) => $slot->rotates)->values();
        $every = max(1, $group->rotate_every_days);
        $anchor = $group->anchor_date->toDateString();

        $currentRound = self::floorDiv(self::daysBetween($anchor, $date), $every);
        $joinRound = self::floorDiv(self::daysBetween($anchor, $member->start_date->toDateString()), $every);
        $steps = $currentRound - $joinRound;

        $startIndex = $rotatingSlots->search(fn (PatternSlot $slot) => $slot->id === $startSlot->id);

        return $rotatingSlots[self::mod($startIndex + $steps, $rotatingSlots->count())];
    }

    /**
     * Shift satu anggota pada tanggal ini: ['slot' => PatternSlot, 'day_index' => int, 'shift_id' => ?string].
     * null = anggota tidak aktif; shift_id null = libur.
     */
    public static function shiftOn(WorkGroup $group, Collection $slots, int $cycleDays, WorkGroupMember $member, string $date): ?array
    {
        $slot = self::slotOn($group, $slots, $member, $date);
        if ($slot === null) {
            return null;
        }

        $dayIndex = self::dayIndex($group, $cycleDays, $date);

        return ['slot' => $slot, 'day_index' => $dayIndex, 'shift_id' => $slot->shiftIds()[$dayIndex - 1] ?? null];
    }

    /**
     * Slot yang KOSONG (tidak dipegang anggota aktif mana pun) pada tanggal ini, beserta shiftnya hari itu:
     * [['slot' => PatternSlot, 'shift_id' => ?string], ...].
     *
     * @param  iterable<WorkGroupMember>  $members
     */
    public static function vacancies(WorkGroup $group, Collection $slots, int $cycleDays, iterable $members, string $date): array
    {
        $occupiedIds = [];
        foreach ($members as $member) {
            $slot = self::slotOn($group, $slots, $member, $date);
            if ($slot !== null) {
                $occupiedIds[] = $slot->id;
            }
        }

        $dayIndex = self::dayIndex($group, $cycleDays, $date);

        return $slots
            ->reject(fn (PatternSlot $slot) => in_array($slot->id, $occupiedIds, true))
            ->map(fn (PatternSlot $slot) => ['slot' => $slot, 'shift_id' => $slot->shiftIds()[$dayIndex - 1] ?? null])
            ->values()
            ->all();
    }

    /**
     * Slot ini hanya boleh dipegang SATU orang? Ya untuk slot yang ikut bergilir di regu Bergilir
     * (kalau dua orang, keduanya bergerak bersama dan ada slot lain yang kosong).
     */
    public static function isSingleHolderSlot(WorkGroup $group, PatternSlot $slot): bool
    {
        return $group->rotation_mode === RotationMode::Rotating && $slot->rotates;
    }

    /** Selisih hari dari $from ke $to (bisa negatif). "2026-10-01" -> "2026-10-08" = 7. */
    public static function daysBetween(string $from, string $to): int
    {
        return intdiv(strtotime("{$to} 00:00:00 UTC") - strtotime("{$from} 00:00:00 UTC"), 86400);
    }

    /** Semua tanggal dari $from sampai $to (termasuk keduanya). */
    public static function datesBetween(string $from, string $to): array
    {
        $dates = [];
        for ($time = strtotime("{$from} UTC"); $time <= strtotime("{$to} UTC"); $time += 86400) {
            $dates[] = gmdate('Y-m-d', $time);
        }

        return $dates;
    }

    /** Sisa bagi yang selalu positif: mod(-1, 4) = 3 (bukan -1 seperti operator %). */
    private static function mod(int $value, int $divisor): int
    {
        return (($value % $divisor) + $divisor) % $divisor;
    }

    /** Pembagian yang dibulatkan ke bawah, juga untuk angka negatif: floorDiv(-1, 7) = -1 (bukan 0). */
    private static function floorDiv(int $value, int $divisor): int
    {
        return intdiv($value - self::mod($value, $divisor), $divisor);
    }
}
