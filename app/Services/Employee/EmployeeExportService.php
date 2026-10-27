<?php

namespace App\Services\Employee;

use App\Enums\EducationLevel;
use App\Enums\EmploymentStatus;
use App\Enums\FamilyRelationship;
use App\Enums\Gender;
use App\Models\Employee;
use App\Models\EmployeeEducation;
use App\Models\EmployeeFamilyMember;
use App\Models\PositionAssignment;
use App\Services\EmployeeStatusService;
use App\Support\DataPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Menyusun BARIS DATA ekspor Data Karyawan: satu baris per karyawan, berisi [huruf kolom => nilai].
 *
 *   rows('Aktif')  -> [['A' => 1, 'B' => 'II', 'E' => '0107...', 'G' => 'ASEP ...', ...], ...]
 *
 * Isi tiap kolom ada di row(). Format kolom (lebar, warna, dll.) ada di EmployeeExportColumns.
 * Aturan teks: semua teks HURUF BESAR, kecuali email (huruf kecil).
 */
class EmployeeExportService
{
    /** Usia pensiun (sama dengan aplikasi): karpim BRM-1 s.d. BRM-3 = 56 tahun, karpel BRM-4 s.d. BRM-6 = 55 tahun. */
    private const RETIREMENT_AGE_KARPIM = 56;

    private const RETIREMENT_AGE_KARPEL = 55;

    /** Riwayat jabatan sebelumnya yang dicantumkan: [kolom nama jabatan, kolom TMT, kolom lama menjabat]. */
    private const PREVIOUS_POSITION_COLUMNS = [
        ['CL', 'CM', 'CN'],
        ['CO', 'CP', 'CQ'],
        ['CR', 'CS', 'CT'],
        ['CU', 'CV', 'CW'],
    ];

    /** Anak ke-1 s.d. ke-4: [kolom nama, kolom tanggal lahir, kolom NIK KTP]. */
    private const CHILD_COLUMNS = [
        ['BF', 'BG', 'BH'],
        ['BI', 'BJ', 'BK'],
        ['BL', 'BM', 'BN'],
        ['BO', 'BP', 'BQ'],
    ];

    /** Komoditi (kolom C): "TEH" untuk yang ditempatkan di Afdeling Gunung Mas, selain itu "AGROWISATA". */
    private const COMMODITY_DEFAULT = 'AGROWISATA';

    /** [Kode afdeling sub unit => komoditi]. */
    private const COMMODITY_BY_AFDELING = [
        'AFD50' => 'TEH',   // Afdeling Gunung Mas (kebun teh)
    ];

    /** Kolom nama sekolah per jenjang pendidikan. */
    private const SCHOOL_COLUMNS = [
        'BY' => [EducationLevel::Sd],
        'BZ' => [EducationLevel::Smp],
        'CA' => [EducationLevel::Sma],
        'CB' => [EducationLevel::D3, EducationLevel::D4],
        'CC' => [EducationLevel::S1],
        'CD' => [EducationLevel::S2],
        'CE' => [EducationLevel::S3],
    ];

    public function __construct(private EmployeeStatusService $statusService) {}

    /**
     * Baris data karyawan dengan status kepegawaian tertentu (null = semua karyawan), per periode data
     * (null = hari ini; lihat App\Support\DataPeriod). Jabatan, usia, pensiun, & riwayat jabatan dihitung
     * per tanggal acuan periode.
     * Urutan: level BRM jabatan utama dari yang tertinggi (BRM-1 dulu), lalu nama A–Z;
     * karyawan yang belum ditempatkan paling bawah.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(?string $activeStatus, ?string $period = null): array
    {
        $asOf = DataPeriod::asOfOrToday($period);

        $employees = Employee::query()
            // Tanpa periode: saring dari kolom status (per hari ini), seperti halaman Data Karyawan.
            ->when($activeStatus !== null && $period === null, fn ($query) => $query->where('active_status', $activeStatus))
            ->with([
                'assignments.position', 'assignments.subUnit', 'assignments.placementObjects',
                'activationItems.activation', 'terminationItems.termination', 'educations', 'familyMembers',
            ])
            ->get()
            // Dengan periode: status dihitung per tanggal acuan.
            ->when($activeStatus !== null && $period !== null, fn ($employees) => $employees->filter(
                fn (Employee $employee) => $this->statusService->statusOn($employee, $asOf)->value === $activeStatus,
            ))
            ->sortBy(fn (Employee $employee) => [$this->brmLevelForSorting($employee, $asOf), mb_strtoupper($employee->name)])
            ->values();

        return $employees->map(fn (Employee $employee, int $index) => $this->row($employee, $index + 1, $asOf))->all();
    }

    /** Nama file, mis. "Database Karyawan PTPN I Regional 2 Unit Agrowisata - September 2026.xlsx" (bulan periode). */
    public function fileName(?string $period = null): string
    {
        return 'Database Karyawan PTPN I Regional 2 Unit Agrowisata - '.DataPeriod::label($period).'.xlsx';
    }

    /**
     * Isi SATU baris Excel untuk seorang karyawan, per tanggal acuan $asOf.
     * Kolom yang tidak disebut di sini dibiarkan kosong.
     *
     * @return array<string, mixed>
     */
    private function row(Employee $employee, int $number, Carbon $asOf): array
    {
        $main = $employee->mainAssignment($asOf);
        $highestEducation = $employee->highestEducation();

        $row = [
            // --- Identitas -----------------------------------------------------------
            'A' => $number,
            'B' => 'II',
            'C' => $this->commodity($main),
            'D' => 'NON KSO',
            'E' => $employee->registration_number,
            'F' => $employee->nik,
            'G' => $this->upper($employee->name),
            'H' => $this->upper($this->titles($employee, 'title_front')),
            'I' => $this->upper($this->titles($employee, 'title_back')),

            // --- Penempatan (jabatan utama) --------------------------------------
            'J' => $this->upper($main?->subUnit->afdeling_code),
            'K' => $this->upper($main?->placementObjects->pluck('name')->implode(', ')),
            'L' => $this->upper($main?->subUnit->name),

            // --- Biodata -------------------------------------------------------------
            'M' => $this->upper($employee->birth_place),
            'N' => $employee->birth_date,
            'O' => $employee->birth_date === null ? null : (int) $employee->birth_date->diffInYears($asOf),
            'P' => match ($employee->gender) {
                Gender::Male->value => 'L',
                Gender::Female->value => 'P',
                default => null,
            },
            'Q' => $this->upper($main?->position->title),
            'U' => $this->employmentStatusLabel($employee->employment_status),

            // --- Pendidikan terakhir & pensiun ---------------------------------------
            'AD' => $highestEducation?->level,
            'AE' => $this->upper($highestEducation?->major),
            'AI' => $this->retirementDate($employee, $main),

            // --- Kontak ----------------------------------------------------------------
            'AU' => $employee->ktp_number,
            'AW' => $this->upper($employee->address),
            'AY' => $employee->email === null ? null : mb_strtolower($employee->email),
            'AZ' => $employee->phone,

            // --- Pendidikan terakhir (rinci) -------------------------------------------
            'BV' => $highestEducation?->level,
            'BW' => $this->upper($highestEducation?->major),
            'BX' => $this->upper($highestEducation?->institution),

            // --- Tanggal masuk & jabatan saat ini ---------------------------------------
            'CF' => $employee->hire_date,
            'CJ' => $this->upper($main?->position->title),
            'CK' => $main?->start_date,
        ];

        return $row + $this->spouseColumns($employee) + $this->childColumns($employee)
            + $this->schoolColumns($employee) + $this->previousPositionColumns($employee, $asOf);
    }

    // -------------------------------------------------------------------------
    // Isi kolom yang perlu dihitung
    // -------------------------------------------------------------------------

    /** Suami/Istri (yang pertama): BC nama, BD tanggal lahir, BE NIK KTP. */
    private function spouseColumns(Employee $employee): array
    {
        $spouse = $employee->familyMembers->first(fn (EmployeeFamilyMember $member) => in_array(
            $member->relationship,
            [FamilyRelationship::Husband->value, FamilyRelationship::Wife->value],
            true,
        ));

        return [
            'BC' => $this->upper($spouse?->name),
            'BD' => $spouse?->birth_date,
            'BE' => $spouse?->ktp_number,
        ];
    }

    /** Anak ke-1 s.d. ke-4, urut dari yang tertua (lihat CHILD_COLUMNS). */
    private function childColumns(Employee $employee): array
    {
        $children = $employee->familyMembers
            ->where('relationship', FamilyRelationship::Child->value)
            ->sortBy('birth_date')
            ->values();

        $columns = [];
        foreach (self::CHILD_COLUMNS as $index => [$nameColumn, $birthDateColumn, $ktpColumn]) {
            $child = $children->get($index);
            $columns[$nameColumn] = $this->upper($child?->name);
            $columns[$birthDateColumn] = $child?->birth_date;
            $columns[$ktpColumn] = $child?->ktp_number;
        }

        return $columns;
    }

    /** Nama sekolah/universitas per jenjang (lihat SCHOOL_COLUMNS). Lebih dari satu digabung ", ". */
    private function schoolColumns(Employee $employee): array
    {
        $columns = [];
        foreach (self::SCHOOL_COLUMNS as $column => $levels) {
            $levelValues = array_map(fn (EducationLevel $level) => $level->value, $levels);
            $columns[$column] = $this->upper($this->educations($employee)
                ->whereIn('level', $levelValues)
                ->pluck('institution')
                ->filter()
                ->implode(', '));
        }

        return $columns;
    }

    /** Sampai 4 jabatan SEBELUMNYA yang sudah selesai sebelum tanggal acuan, dari yang terbaru (lihat PREVIOUS_POSITION_COLUMNS). */
    private function previousPositionColumns(Employee $employee, Carbon $asOf): array
    {
        $previous = $employee->assignments
            ->filter(fn (PositionAssignment $assignment) => $assignment->end_date?->lessThan($asOf) ?? false)
            ->sortByDesc('start_date')
            ->values();

        $columns = [];
        foreach (self::PREVIOUS_POSITION_COLUMNS as $index => [$titleColumn, $startColumn, $durationColumn]) {
            $assignment = $previous->get($index);
            $columns[$titleColumn] = $this->upper($assignment?->position->title);
            $columns[$startColumn] = $assignment?->start_date;
            $columns[$durationColumn] = $assignment === null
                ? null
                : $this->upper($this->formatMonths((int) $assignment->start_date->diffInMonths($assignment->end_date)));
        }

        return $columns;
    }

    /**
     * Gelar dari riwayat pendidikan, dari jenjang tertinggi: "S.T., M.T." -> beberapa gelar digabung ", ".
     *
     * @param  'title_front'|'title_back'  $field
     */
    private function titles(Employee $employee, string $field): string
    {
        return $this->educations($employee)->pluck($field)->filter()->unique()->implode(', ');
    }

    /**
     * Riwayat pendidikan dari jenjang tertinggi (S3 … SD).
     *
     * @return Collection<int, EmployeeEducation>
     */
    private function educations(Employee $employee): Collection
    {
        return collect($employee->educationsFromHighest());
    }

    /**
     * Tanggal pensiun = tanggal lahir + usia pensiun. Hanya karyawan Tetap yang sudah ditempatkan
     * (sama dengan aplikasi: belum ditempatkan -> belum ditampilkan).
     */
    private function retirementDate(Employee $employee, ?PositionAssignment $main): ?Carbon
    {
        if ($employee->employment_status !== EmploymentStatus::Permanent->value || $employee->birth_date === null || $main === null) {
            return null;
        }

        $retirementAge = $main->position->brm_level <= 3 ? self::RETIREMENT_AGE_KARPIM : self::RETIREMENT_AGE_KARPEL;

        return $employee->birth_date->copy()->addYearsNoOverflow($retirementAge);
    }

    /** Komoditi dari sub unit jabatan utama (lihat COMMODITY_BY_AFDELING). */
    private function commodity(?PositionAssignment $main): string
    {
        return self::COMMODITY_BY_AFDELING[$main?->subUnit->afdeling_code ?? ''] ?? self::COMMODITY_DEFAULT;
    }

    /** "Karyawan Tetap" -> "TETAP", "Karyawan Daily Worker" -> "DAILY WORKER". */
    private function employmentStatusLabel(string $employmentStatus): string
    {
        return $this->upper(str_replace('Karyawan ', '', $employmentStatus));
    }

    /** Lama waktu, sama dengan aplikasi: 27 -> "2 tahun 3 bulan", 5 -> "5 bulan", 0 -> "kurang dari 1 bulan". */
    private function formatMonths(int $totalMonths): string
    {
        $years = intdiv($totalMonths, 12);
        $months = $totalMonths % 12;

        if ($years === 0) {
            return $months === 0 ? 'kurang dari 1 bulan' : "{$months} bulan";
        }

        return $months === 0 ? "{$years} tahun" : "{$years} tahun {$months} bulan";
    }

    /** Level BRM jabatan utama pada tanggal acuan, untuk mengurutkan; belum ditempatkan = paling bawah. */
    private function brmLevelForSorting(Employee $employee, Carbon $asOf): int
    {
        return $employee->mainAssignment($asOf)?->position->brm_level ?? PHP_INT_MAX;
    }

    /** Teks HURUF BESAR; teks kosong -> null. */
    private function upper(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : mb_strtoupper($text);
    }
}
