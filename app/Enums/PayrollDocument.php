<?php

namespace App\Enums;

/**
 * Dokumen ekspor Daftar Upah (GET /payroll/export?document=). Setiap dokumen = REKAP + satu bagian per kelompok.
 *
 *   daftar_upah   Daftar Upah (rincian seperti DU "Gunung Mas")
 *   payroll       Payroll / transfer (rekening & jumlah diterima)
 *   spkl_all      SPKL Lembur Reguler + DW (satu blok per karyawan berisi kedua jenis)  — hanya Casual
 *   spkl_reguler  SPKL Lembur Reguler                                                   — Casual & Daily Worker
 *   spkl_dw       SPKL Lembur DW                                                        — hanya Casual
 */
enum PayrollDocument: string
{
    use HasValues;

    case DaftarUpah = 'daftar_upah';
    case Payroll = 'payroll';
    case SpklAll = 'spkl_all';
    case SpklRegular = 'spkl_reguler';
    case SpklDw = 'spkl_dw';

    /** Awal nama file, mis. "SPKL Lembur Reguler + DW". */
    public function label(): string
    {
        return match ($this) {
            self::DaftarUpah => 'Daftar Upah',
            self::Payroll => 'Payroll',
            self::SpklAll => 'SPKL Lembur Reguler + DW',
            self::SpklRegular => 'SPKL Lembur Reguler',
            self::SpklDw => 'SPKL Lembur DW',
        };
    }

    public function isSpkl(): bool
    {
        return in_array($this, [self::SpklAll, self::SpklRegular, self::SpklDw], true);
    }

    /** Jenis lembur di dokumen SPKL ini (kosong untuk Daftar Upah & Payroll). @return list<OvertimeType> */
    public function overtimeTypes(): array
    {
        return match ($this) {
            self::SpklAll => [OvertimeType::Regular, OvertimeType::DailyWorker],
            self::SpklRegular => [OvertimeType::Regular],
            self::SpklDw => [OvertimeType::DailyWorker],
            default => [],
        };
    }

    /** Memuat Lembur DW (hanya untuk Casual)? */
    public function usesDw(): bool
    {
        return in_array(OvertimeType::DailyWorker, $this->overtimeTypes(), true);
    }
}
