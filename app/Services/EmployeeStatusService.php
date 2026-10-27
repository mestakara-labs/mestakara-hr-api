<?php

namespace App\Services;

use App\Enums\EmployeeActiveStatus;
use App\Enums\ProposalStatus;
use App\Models\Employee;
use App\Models\EmployeeActivationItem;
use App\Models\EmployeeTerminationItem;
use Illuminate\Support\Carbon;

/**
 * STATUS KEPEGAWAIAN karyawan, dihitung dari RIWAYAT (bukan ditimpa):
 *
 *   kejadian "aktif"    = item usulan Penambahan yang BERLAKU
 *   kejadian "nonaktif" = item usulan Penonaktifan yang BERLAKU
 *
 * Status pada suatu tanggal = kejadian TERAKHIR yang TMT-nya sudah tiba:
 *   Aktif / Nonaktif, atau Belum Aktif kalau belum ada kejadian sama sekali.
 *
 * employees.active_status & inactive_since hanya SALINAN untuk filter cepat. Salinan ini diperbarui
 * refresh() setiap kali usulan berlaku / dibatalkan, dan setiap hari oleh perintah
 * `php artisan hr:refresh-employee-status` (untuk TMT yang baru tiba hari itu).
 */
class EmployeeStatusService
{
    /**
     * Kejadian status yang TMT-nya ≤ $until (bawaan: hari ini), urut dari yang paling lama.
     * Kalau satu tanggal ada dua kejadian, "aktif" diurutkan lebih dulu.
     *
     * @return list<array{date: Carbon, is_active: bool}>
     */
    public function events(Employee $employee, ?Carbon $until = null): array
    {
        $until ??= Carbon::today();
        $employee->loadMissing(['activationItems.activation', 'terminationItems.termination']);

        $activated = $employee->activationItems
            ->filter(fn (EmployeeActivationItem $item) => $item->activation->status === ProposalStatus::Effective)
            ->map(fn (EmployeeActivationItem $item) => ['date' => $item->effective_date, 'is_active' => true]);

        $terminated = $employee->terminationItems
            ->filter(fn (EmployeeTerminationItem $item) => $item->termination->status === ProposalStatus::Effective)
            ->map(fn (EmployeeTerminationItem $item) => ['date' => $item->effective_date, 'is_active' => false]);

        return $activated->concat($terminated)
            ->filter(fn (array $event) => $event['date'] !== null && $event['date']->lessThanOrEqualTo($until))
            ->sortBy([
                fn (array $a, array $b) => $a['date'] <=> $b['date'],
                fn (array $a, array $b) => $b['is_active'] <=> $a['is_active'],
            ])
            ->values()
            ->all();
    }

    /** Status kepegawaian pada tanggal tertentu (bawaan: hari ini). */
    public function statusOn(Employee $employee, ?Carbon $date = null): EmployeeActiveStatus
    {
        $last = collect($this->events($employee, $date))->last();

        if ($last === null) {
            return EmployeeActiveStatus::Pending;
        }

        return $last['is_active'] ? EmployeeActiveStatus::Active : EmployeeActiveStatus::Inactive;
    }

    /**
     * TMT kejadian TERAKHIR yang sudah BERLAKU (termasuk yang TMT-nya belum tiba):
     *   isActive true  -> TMT aktif terakhir (Penambahan)
     *   isActive false -> TMT nonaktif terakhir (Penonaktifan)
     * null kalau belum pernah ada.
     */
    public function lastEventDate(Employee $employee, bool $isActive): ?Carbon
    {
        $farFuture = Carbon::create(9999, 12, 31);

        return collect($this->events($employee, $farFuture))
            ->where('is_active', $isActive)
            ->last()['date'] ?? null;
    }

    /** Sudah terjadwal NONAKTIF (penonaktifan Berlaku dengan TMT kapan pun yang menjadi kejadian terakhir)? */
    public function isScheduledInactive(Employee $employee): bool
    {
        $last = collect($this->events($employee, Carbon::create(9999, 12, 31)))->last();

        return $last !== null && ! $last['is_active'];
    }

    /**
     * TMT nonaktif pada tanggal tertentu (bawaan: hari ini): TMT penonaktifan terakhir yang sudah tiba,
     * hanya kalau karyawan memang Nonaktif pada tanggal itu. null = tidak sedang nonaktif.
     */
    public function inactiveSince(Employee $employee, ?Carbon $date = null): ?Carbon
    {
        $last = collect($this->events($employee, $date))->last();

        return $last !== null && ! $last['is_active'] ? $last['date'] : null;
    }

    /**
     * Tanggal-tanggal ketika karyawan AKTIF dalam rentang [$from, $to], format "YYYY-MM-DD".
     * Aturannya sama dengan statusOn(): status pada suatu tanggal = kejadian Berlaku terakhir dengan TMT ≤ tanggal itu.
     *
     * @return list<string>
     */
    public function activeDatesBetween(Employee $employee, Carbon $from, Carbon $to): array
    {
        $events = $this->events($employee, $to);
        $dates = [];
        $index = 0;
        $isActive = false;

        for ($date = $from->copy()->startOfDay(); $date->lessThanOrEqualTo($to); $date->addDay()) {
            while (isset($events[$index]) && $events[$index]['date']->lessThanOrEqualTo($date)) {
                $isActive = $events[$index]['is_active'];
                $index++;
            }
            if ($isActive) {
                $dates[] = $date->toDateString();
            }
        }

        return $dates;
    }

    /**
     * MASA KERJA dalam bulan penuh = jumlah semua masa AKTIF (masa nonaktif tidak dihitung),
     * dihitung sampai tanggal tertentu (bawaan: hari ini):
     *
     *   aktif 2010-01-01 ── nonaktif 2015-01-01 ·· aktif lagi 2020-01-01 ── hari ini 2026-01-01
     *        60 bulan                                       72 bulan            -> 132 bulan
     */
    public function serviceMonths(Employee $employee, ?Carbon $until = null): int
    {
        $until ??= Carbon::today();
        $total = 0;
        $activeSince = null;

        foreach ($this->events($employee, $until) as $event) {
            if ($event['is_active'] && $activeSince === null) {
                $activeSince = $event['date'];
            }
            if (! $event['is_active'] && $activeSince !== null) {
                $total += $this->fullMonths($activeSince, $event['date']);
                $activeSince = null;
            }
        }

        if ($activeSince !== null) {
            $total += $this->fullMonths($activeSince, $until);
        }

        return max($total, 0);
    }

    /**
     * Perbarui salinan status (active_status, inactive_since) dan akun login karyawan:
     *   - menjadi Nonaktif -> akun login dinonaktifkan
     *   - Aktif kembali    -> akun login yang dulu dinonaktifkan diaktifkan lagi
     */
    public function refresh(Employee $employee): void
    {
        $employee->unsetRelation('activationItems')->unsetRelation('terminationItems');

        $events = $this->events($employee);
        $last = end($events) ?: null;
        $status = $this->statusOn($employee);

        $employee->update([
            'active_status' => $status->value,
            'inactive_since' => $status === EmployeeActiveStatus::Inactive ? $last['date'] : null,
        ]);

        $user = $employee->user;
        if ($user === null) {
            return;
        }

        if ($status === EmployeeActiveStatus::Inactive && $user->is_active) {
            $user->update(['is_active' => false, 'deactivated_at' => now()]);
            $user->tokens()->delete();
        } elseif ($status === EmployeeActiveStatus::Active && ! $user->is_active && $user->deactivated_at !== null) {
            $user->update(['is_active' => true, 'deactivated_at' => null]);
        }
    }

    /** Perbarui status SEMUA karyawan (dipakai perintah harian). Hasilnya jumlah karyawan yang statusnya berubah. */
    public function refreshAll(): int
    {
        $changed = 0;

        Employee::query()->with(['activationItems.activation', 'terminationItems.termination'])->each(function (Employee $employee) use (&$changed) {
            $before = $employee->active_status;
            $this->refresh($employee);

            if ($employee->active_status !== $before) {
                $changed++;
            }
        });

        return $changed;
    }

    /** Jumlah bulan penuh antara dua tanggal, mis. 2020-01-15 s.d. 2020-03-14 = 1 bulan. */
    private function fullMonths(Carbon $from, Carbon $to): int
    {
        return (int) floor($from->diffInMonths($to));
    }
}
