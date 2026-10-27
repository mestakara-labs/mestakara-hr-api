<?php

namespace App\Services\Payroll;

use App\Enums\PayrollLockAction;
use App\Enums\PayrollPeriodStatus;
use App\Enums\SignatureOwnerType;
use App\Models\Division;
use App\Models\PayrollGroup;
use App\Models\PayrollPeriod;
use App\Models\PayrollPeriodEvent;
use App\Models\PayrollSnapshotRow;
use App\Models\PayrollSnapshotSigner;
use App\Services\Attendance\AttendancePeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * KUNCI & BUKA KUNCI periode upah.
 *
 *   kunci  hanya bulan yang sudah berakhir; angka semua karyawan (berisi kelompok & bidang, jadi cukup untuk kedua
 *          group_by) + penanda tangan Umum, setiap kelompok, & setiap bidang disimpan sebagai POTRET.
 *          Setelah itu absensi, lembur, potongan, & skema upah yang berlaku ≤ akhir periode ditolak.
 *   buka   wajib alasan; potret dihapus, periode kembali Draf.
 *   Keduanya dicatat di riwayat (payroll_period_events).
 */
class PayrollLockService
{
    public function lock(AttendancePeriod $period): void
    {
        if ($period->isLocked()) {
            throw ValidationException::withMessages(['period' => "Periode upah {$period->label()} sudah dikunci"]);
        }
        if (! PayrollReport::hasEnded($period)) {
            throw ValidationException::withMessages(['period' => 'Periode belum berakhir']);
        }

        $data = PayrollData::load($period);
        $now = now();

        DB::transaction(function () use ($period, $data, $now) {
            foreach (array_chunk($data->snapshotInserts(), 200) as $chunk) {
                PayrollSnapshotRow::query()->insert(array_map(fn (array $row) => [
                    ...$row, 'id' => (string) Str::uuid7(), 'created_at' => $now,
                ], $chunk));
            }

            $signerRows = $data->signatures->snapshotRows($period->period, [
                SignatureOwnerType::PayrollGroup->value => PayrollGroup::query()->pluck('id')->all(),
                SignatureOwnerType::Division->value => Division::query()->pluck('id')->all(),
            ]);
            foreach ($signerRows as $row) {
                PayrollSnapshotSigner::query()->create($row);
            }

            PayrollPeriod::query()->updateOrCreate(
                ['period' => $period->period],
                ['status' => PayrollPeriodStatus::Locked, 'locked_at' => $now, 'locked_by' => auth()->id()],
            );
            $this->log($period, PayrollLockAction::Lock, null);
        });
    }

    public function unlock(AttendancePeriod $period, string $reason): void
    {
        if (! $period->isLocked()) {
            throw ValidationException::withMessages(['period' => "Periode upah {$period->label()} belum dikunci"]);
        }

        DB::transaction(function () use ($period, $reason) {
            PayrollSnapshotRow::query()->where('period', $period->period)->delete();
            PayrollSnapshotSigner::query()->where('period', $period->period)->delete();
            PayrollPeriod::query()->where('period', $period->period)->update([
                'status' => PayrollPeriodStatus::Draft, 'locked_at' => null, 'locked_by' => null,
            ]);
            $this->log($period, PayrollLockAction::Unlock, $reason);
        });
    }

    /** Tolak perubahan data upah pada periode terkunci (422). */
    public static function ensureNotLocked(AttendancePeriod $period, string $field = 'period'): void
    {
        if ($period->isLocked()) {
            throw ValidationException::withMessages([$field => "Periode upah {$period->label()} sudah dikunci"]);
        }
    }

    private function log(AttendancePeriod $period, PayrollLockAction $action, ?string $reason): void
    {
        PayrollPeriodEvent::query()->create([
            'period' => $period->period,
            'action' => $action,
            'reason' => $reason,
            'created_by' => auth()->id(),
        ]);
    }
}
