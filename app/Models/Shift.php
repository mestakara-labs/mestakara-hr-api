<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shift = jam kerja (tabel shifts), mis. Pagi 07:00–12:00 atau Malam 22:00–06:00.
 *
 * Jam disimpan sebagai TIME; dibaca sebagai teks "HH:MM" lewat startTime() / endTime().
 * Jam selesai ≤ jam mulai = LINTAS TENGAH MALAM (selesai keesokan harinya).
 */
#[Fillable([
    'code', 'name', 'start_time', 'end_time', 'break_minutes', 'late_tolerance_minutes',
    'checkin_window_before_minutes', 'checkout_window_after_minutes', 'color', 'description',
])]
class Shift extends Model
{
    use HasFactory, HasUuids;

    private const MINUTES_PER_DAY = 24 * 60;

    protected function casts(): array
    {
        return [
            'break_minutes' => 'integer',
            'late_tolerance_minutes' => 'integer',
            'checkin_window_before_minutes' => 'integer',
            'checkout_window_after_minutes' => 'integer',
        ];
    }

    /** Hari pola yang memakai shift ini. */
    public function patternSlotDays(): HasMany
    {
        return $this->hasMany(PatternSlotDay::class);
    }

    /** Berapa pola kerja yang memakai shift ini (shift yang dipakai tidak bisa dihapus). */
    public function patternCount(): int
    {
        return PatternSlotDay::query()
            ->join('pattern_slots', 'pattern_slots.id', '=', 'pattern_slot_days.pattern_slot_id')
            ->where('pattern_slot_days.shift_id', $this->id)
            ->distinct()
            ->count('pattern_slots.work_pattern_id');
    }

    /** Jam mulai "HH:MM". */
    public function startTime(): string
    {
        return substr((string) $this->start_time, 0, 5);
    }

    /** Jam selesai "HH:MM". */
    public function endTime(): string
    {
        return substr((string) $this->end_time, 0, 5);
    }

    /** Selesai keesokan harinya? Cth. 22:00–06:00 -> true. */
    public function crossesMidnight(): bool
    {
        return self::minutesOf($this->endTime()) <= self::minutesOf($this->startTime());
    }

    /** Lama shift dalam menit (termasuk istirahat). 07:00–12:00 -> 300; 22:00–06:00 -> 480. */
    public function durationMinutes(): int
    {
        return self::durationBetween($this->startTime(), $this->endTime());
    }

    /** Lama shift antara dua jam "HH:MM" (lintas tengah malam dihitung ke hari berikutnya). */
    public static function durationBetween(string $start, string $end): int
    {
        $difference = self::minutesOf($end) - self::minutesOf($start);

        return $difference > 0 ? $difference : $difference + self::MINUTES_PER_DAY;
    }

    /** "07:30" -> 450 (menit sejak tengah malam). */
    private static function minutesOf(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }
}
