<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/**
 * "NILAI YANG BERLAKU PADA TANGGAL X" untuk semua tabel riwayat ber-effective_date
 * (rekening, cost center, skema upah, ...):
 *
 *   catatan dengan effective_date ≤ X yang paling akhir;
 *   kalau effective_date-nya sama, yang dibuat paling akhir (created_at, lalu id — UUIDv7 urut waktu).
 *
 * Contoh:
 *   EffectiveRecords::on($employee->bankAccounts, Carbon::parse('2026-09-30'))   // null = belum ada
 *   EffectiveRecords::newestFirst($employee->bankAccounts()) ->get()               // riwayat, terbaru di atas
 */
class EffectiveRecords
{
    /**
     * Catatan yang berlaku pada tanggal $date dari riwayat yang SUDAH dimuat (tanpa query tambahan).
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $records
     * @return TModel|null
     */
    public static function on(Collection $records, Carbon $date): ?Model
    {
        return $records
            ->filter(fn (Model $record) => $record->effective_date->lessThanOrEqualTo($date))
            ->sort(fn (Model $a, Model $b) => [$b->effective_date, $b->created_at, $b->id] <=> [$a->effective_date, $a->created_at, $a->id])
            ->first();
    }

    /** Urutkan query riwayat: yang berlaku paling akhir di atas. */
    public static function newestFirst(Builder|Relation $query): Builder|Relation
    {
        return $query->orderByDesc('effective_date')->orderByDesc('created_at')->orderByDesc('id');
    }
}
