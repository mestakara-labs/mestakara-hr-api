<?php

namespace App\Enums;

use App\Models\Employee;

/**
 * KATEGORI KARYAWAN berupah harian, dipisah di Unggah Absensi & ekspor Daftar Upah (?employee_category=):
 *
 *   casual        Karyawan Casual       -> Daftar Hadir, Lembur Reguler, Lembur DW (kode DW = hari ke-21 dst)
 *   daily_worker  Karyawan Daily Worker -> Daftar Hadir, Lembur Reguler (tanpa kode DW & Lembur DW)
 *
 * Kategori = employment_status karyawan SAAT INI.
 * Istilah: "Daily Worker" = kategori ini; "DW" = kode hari / Lembur DW (milik Casual).
 */
enum EmployeeCategory: string
{
    use HasValues;

    case Casual = 'casual';
    case DailyWorker = 'daily_worker';

    public const INVALID_MESSAGE = 'Kategori karyawan harus Casual atau Daily Worker';

    public const DW_ONLY_CASUAL_MESSAGE = 'Lembur DW hanya untuk karyawan Casual';

    public const DW_CODE_ONLY_CASUAL_MESSAGE = 'Kode DW hanya untuk karyawan Casual';

    public function employmentStatus(): EmploymentStatus
    {
        return $this === self::Casual ? EmploymentStatus::Casual : EmploymentStatus::DailyWorker;
    }

    /** "Karyawan Casual" / "Karyawan Daily Worker" (sama dengan teks employment_status). */
    public function label(): string
    {
        return $this->employmentStatus()->value;
    }

    /** "Casual" / "Daily Worker". */
    public function shortLabel(): string
    {
        return $this === self::Casual ? 'Casual' : 'Daily Worker';
    }

    /** Lembur DW & kode DW hanya untuk Casual. */
    public function allowsDw(): bool
    {
        return $this === self::Casual;
    }

    /** @return list<AttendanceImportType> jenis unggahan (tab) kategori ini, urut tampil */
    public function importTypes(): array
    {
        return $this->allowsDw()
            ? [AttendanceImportType::AttendanceList, AttendanceImportType::RegularOvertime, AttendanceImportType::DailyWorkerOvertime]
            : [AttendanceImportType::AttendanceList, AttendanceImportType::RegularOvertime];
    }

    /** Kategori dari employment_status; null = bukan Casual / Daily Worker. */
    public static function fromEmploymentStatus(?string $employmentStatus): ?self
    {
        return match ($employmentStatus) {
            EmploymentStatus::Casual->value => self::Casual,
            EmploymentStatus::DailyWorker->value => self::DailyWorker,
            default => null,
        };
    }

    public static function of(Employee $employee): ?self
    {
        return self::fromEmploymentStatus($employee->employment_status);
    }

    /** Pesan baris unggahan berisi karyawan kategori lain, mis. "Karyawan Daily Worker — unggah di tab Karyawan Daily Worker". */
    public function wrongTabMessage(): string
    {
        return "{$this->label()} — unggah di tab {$this->label()}";
    }
}
