<?php

namespace App\Services\Schedule;

use App\Enums\SchedulePeriodStatus;
use App\Enums\ScheduleSource;
use App\Models\Schedule;
use App\Models\SchedulePeriod;
use App\Models\Shift;
use App\Models\WorkGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ALUR jadwal kerja resmi (periode = satu regu × satu bulan):
 *
 *   create()      Buat Jadwal (Draf) — hanya bulan berjalan & bulan depan, sekali per regu per bulan
 *   publish()     Draf -> Terbit
 *   lock()        Terbit -> Terkunci (tidak bisa diubah lagi)
 *   delete()      hapus Draf
 *   updateCell()  ubah shift satu sel (alasan wajib kalau Terbit), dicatat di riwayat
 *   generateNextMonth()  dipakai cron tanggal 20: buat Draf bulan depan untuk semua regu
 */
class SchedulePeriodService
{
    /** Banyaknya bulan yang boleh dibuat jadwalnya: bulan berjalan + bulan depan. */
    private const WINDOW_MONTHS = 2;

    private const MONTH_NAMES = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    public function __construct(private ScheduleGenerator $generator) {}

    /** Buat jadwal Draf satu regu untuk satu bulan "YYYY-MM". `$userId` null = dibuat Sistem (cron). */
    public function create(WorkGroup $group, string $month, ?string $userId): SchedulePeriod
    {
        $position = $this->monthPosition($month);

        abort_if($position === 'past', 409, "{$this->formatMonth($month)} sudah lewat; jadwalnya tidak bisa dibuat lagi.");
        abort_if(
            $position === 'future',
            409,
            "{$this->formatMonth($month)} masih terlalu jauh. Jadwal hanya bisa dibuat untuk bulan berjalan dan bulan depan.",
        );
        abort_if(
            $group->schedulePeriods()->where('month', $month)->exists(),
            409,
            "Jadwal {$group->name} untuk {$this->formatMonth($month)} sudah ada.",
        );

        return DB::transaction(function () use ($group, $month, $userId) {
            $period = $group->schedulePeriods()->create([
                'month' => $month,
                'status' => SchedulePeriodStatus::Draft,
                'generated_at' => now(),
                'generated_by' => $userId,
            ]);

            $this->generator->fill($period);

            return $period;
        });
    }

    /** Hapus jadwal Draf (semua barisnya ikut terhapus). */
    public function delete(SchedulePeriod $period): void
    {
        abort_unless($period->status === SchedulePeriodStatus::Draft, 409, 'Hanya jadwal berstatus Draf yang bisa dihapus.');

        $period->delete();
    }

    /** Draf -> Terbit. */
    public function publish(SchedulePeriod $period): SchedulePeriod
    {
        abort_unless($period->status === SchedulePeriodStatus::Draft, 409, 'Hanya jadwal Draf yang bisa diterbitkan.');

        $period->update(['status' => SchedulePeriodStatus::Published, 'published_at' => now(), 'published_by' => auth()->id()]);

        return $period;
    }

    /** Terbit -> Terkunci (setelah rekap ditutup). */
    public function lock(SchedulePeriod $period): SchedulePeriod
    {
        abort_unless($period->status === SchedulePeriodStatus::Published, 409, 'Hanya jadwal Terbit yang bisa dikunci.');

        $period->update(['status' => SchedulePeriodStatus::Locked, 'locked_at' => now(), 'locked_by' => auth()->id()]);

        return $period;
    }

    /**
     * Ubah shift satu sel ("" / null = libur).
     *   - periode Terkunci tidak bisa diubah; periode Terbit wajib alasan
     *   - setiap ubahan dicatat di riwayat; sel yang kembali sama dengan pola menjadi "Generator" lagi
     */
    public function updateCell(Schedule $entry, ?string $shiftId, ?string $reason): Schedule
    {
        $period = $entry->period;
        abort_unless($period->status->isEditable(), 409, 'Jadwal periode ini sudah dikunci dan tidak bisa diubah.');

        $shiftId = $shiftId ?: null;
        $reason = trim((string) $reason);

        $errors = [];
        if ($shiftId !== null && ! Shift::whereKey($shiftId)->exists()) {
            $errors['shift_id'] = 'Shift tidak ditemukan';
        }
        if ($period->status->requiresChangeReason() && $reason === '') {
            $errors['reason'] = 'Alasan wajib diisi karena jadwal sudah diterbitkan';
        }
        if ($errors === [] && $shiftId === $entry->shift_id) {
            $errors['shift_id'] = 'Pilih shift yang berbeda';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($entry, $shiftId, $reason) {
            $entry->changes()->create([
                'old_shift_id' => $entry->shift_id,
                'new_shift_id' => $shiftId,
                'reason' => $reason ?: null,
                'changed_by' => auth()->id(),
                'changed_at' => now(),
            ]);

            $entry->update([
                'shift_id' => $shiftId,
                ...$this->generator->plannedTimes($entry->work_date->toDateString(), $shiftId),
                'source' => $shiftId === $entry->generated_shift_id ? ScheduleSource::Generator : ScheduleSource::Manual,
            ]);

            return $entry;
        });
    }

    /**
     * Cron tanggal 20: buat jadwal Draf BULAN DEPAN untuk semua regu yang belum punya.
     * Hasilnya jumlah periode yang dibuat.
     */
    public function generateNextMonth(): int
    {
        $nextMonth = Carbon::today()->startOfMonth()->addMonth()->format('Y-m');
        $created = 0;

        foreach (WorkGroup::whereDoesntHave('schedulePeriods', fn ($query) => $query->where('month', $nextMonth))->get() as $group) {
            $this->create($group, $nextMonth, null);
            $created++;
        }

        return $created;
    }

    /** "2026-11" -> "November 2026". */
    public function formatMonth(string $month): string
    {
        [$year, $number] = array_map('intval', explode('-', $month));

        return (self::MONTH_NAMES[$number] ?? $month).' '.$year;
    }

    /** Posisi bulan terhadap jendela jadwal: "past", "window" (boleh dibuat), atau "future". */
    private function monthPosition(string $month): string
    {
        $current = Carbon::today()->startOfMonth();
        $lastAllowed = $current->copy()->addMonths(self::WINDOW_MONTHS - 1)->format('Y-m');

        if ($month < $current->format('Y-m')) {
            return 'past';
        }

        return $month <= $lastAllowed ? 'window' : 'future';
    }
}
