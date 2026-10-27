<?php

namespace App\Enums;

/**
 * Jenis penonaktifan satu karyawan (employee_termination_items.type).
 */
enum TerminationType: string
{
    use HasValues;

    case Retirement = 'Pensiun';
    case EarlyRetirement = 'Pensiun Dini';
    case Resignation = 'Mengundurkan Diri';
    case Dismissal = 'PHK';
    case ContractEnd = 'Kontrak Berakhir';
    case TransferOut = 'Mutasi Keluar';
    case Death = 'Meninggal Dunia';

    /** Wajib disertai alasan? (PHK & Pensiun Dini) */
    public function requiresReason(): bool
    {
        return $this === self::Dismissal || $this === self::EarlyRetirement;
    }

    /** Wajib disertai unit tujuan? (Mutasi Keluar) */
    public function requiresDestinationUnit(): bool
    {
        return $this === self::TransferOut;
    }

    /**
     * Boleh dipakai untuk karyawan dengan status karyawan ini? (aturan ERD Bab 5)
     *   - Pensiun & Pensiun Dini hanya untuk Karyawan Tetap
     *   - Kontrak Berakhir hanya untuk PKWT / Casual / Daily Worker
     */
    public function allowsEmploymentStatus(string $employmentStatus): bool
    {
        $isPermanent = $employmentStatus === EmploymentStatus::Permanent->value;

        return match ($this) {
            self::Retirement, self::EarlyRetirement => $isPermanent,
            self::ContractEnd => ! $isPermanent,
            default => true,
        };
    }
}
