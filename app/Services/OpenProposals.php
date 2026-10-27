<?php

namespace App\Services;

use App\Enums\ProposalStatus;
use App\Enums\TransferStatus;
use App\Models\EmployeeActivation;
use App\Models\EmployeeTermination;
use App\Models\Transfer;

/**
 * Cek apakah seorang karyawan sedang ada di usulan lain yang BELUM SELESAI.
 * Aturan: satu karyawan tidak boleh ada di dua usulan yang belum selesai sekaligus.
 *
 *   Penambahan / Penonaktifan  -> belum selesai = Draf
 *   Mutasi                     -> belum selesai = Draf / Menunggu Dokumen
 *
 * `$exceptId` = id usulan yang sedang diubah (supaya usulan itu sendiri tidak dihitung).
 */
class OpenProposals
{
    public function inDraftActivation(string $employeeId, ?string $exceptId = null): bool
    {
        return EmployeeActivation::query()
            ->where('status', ProposalStatus::Draft->value)
            ->whereHas('items', fn ($query) => $query->where('employee_id', $employeeId))
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function inDraftTermination(string $employeeId, ?string $exceptId = null): bool
    {
        return EmployeeTermination::query()
            ->where('status', ProposalStatus::Draft->value)
            ->whereHas('items', fn ($query) => $query->where('employee_id', $employeeId))
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    /** Usulan mutasi Draf / Menunggu Dokumen yang memuat karyawan ini, atau null. */
    public function openTransfer(string $employeeId, ?string $exceptId = null): ?Transfer
    {
        return Transfer::query()
            ->whereIn('status', [TransferStatus::Draft->value, TransferStatus::WaitingDocument->value])
            ->whereHas('items', fn ($query) => $query->where('employee_id', $employeeId))
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->first();
    }
}
