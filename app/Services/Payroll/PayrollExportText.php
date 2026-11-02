<?php

namespace App\Services\Payroll;

use App\Enums\EmployeeCategory;
use App\Enums\PayrollDocument;
use App\Enums\PayrollGroupBy;
use App\Enums\PayrollPeriodStatus;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Support\DataPeriod;
use App\Support\DocumentStyle;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Teks bersama ekspor Daftar Upah & SPKL: nama file, nama sheet, tanggal tanda tangan, rupiah, dan pemilihan kelompok.
 */
class PayrollExportText
{
    public const COMPANY = DocumentStyle::COMPANY;

    public const UNIT = 'UNIT AGROWISATA';

    /** Nama hari lengkap, Minggu = 0. */
    public const DAY_NAMES = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    /**
     * Nama file "<Dokumen> <Kategori> - <nama kelompok | N Kelompok> - <Bulan Tahun>[ (DRAF)].<ext>", mis.
     * "SPKL Lembur Reguler Karyawan Daily Worker - 3 Kelompok - Oktober 2026 (DRAF).xlsx".
     * Karakter yang tidak boleh di nama file (/ \ : * ? " < > |) diganti "-".
     *
     * @param  list<string>  $groupNames  kelompok yang ada di file
     */
    public static function fileName(PayrollDocument $document, EmployeeCategory $category, array $groupNames, string $monthLabel, bool $isDraft, string $extension): string
    {
        $groups = count($groupNames) === 1 ? $groupNames[0] : count($groupNames).' Kelompok';
        $name = "{$document->label()} {$category->label()} - {$groups} - {$monthLabel}".($isDraft ? ' (DRAF)' : '');

        return preg_replace('/[\/\\\\:*?"<>|]/', '-', $name).'.'.$extension;
    }

    /** "Kelompok Daftar Upah" / "Bidang". */
    public static function groupByLabel(PayrollGroupBy $groupBy): string
    {
        return $groupBy === PayrollGroupBy::PayrollGroup ? 'Kelompok Daftar Upah' : 'Bidang';
    }

    /**
     * Nama sheet Excel yang sah & unik (maks. 31 karakter, tanpa [ ] : * ? / \).
     *
     * @param  array<string, true>  $used  nama yang sudah dipakai (huruf kecil), diperbarui
     */
    public static function sheetTitle(string $name, array &$used): string
    {
        $base = mb_substr(trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $name)), 0, 31) ?: 'Sheet';
        $title = $base;
        $counter = 2;

        while (isset($used[mb_strtolower($title)])) {
            $suffix = " ({$counter})";
            $title = mb_substr($base, 0, 31 - mb_strlen($suffix)).$suffix;
            $counter++;
        }
        $used[mb_strtolower($title)] = true;

        return $title;
    }

    /**
     * Tanggal tanda tangan: $chosen kalau diisi, selain itu tanggal kunci (WIB) kalau periode dikunci, selain itu hari ini.
     * Mis. "02 Oktober 2026".
     */
    public static function signDate(string $period, ?Carbon $chosen = null): string
    {
        $payrollPeriod = $chosen === null ? PayrollPeriod::query()->where('period', $period)->first() : null;
        $date = match (true) {
            $chosen !== null => $chosen,
            $payrollPeriod?->status === PayrollPeriodStatus::Locked && $payrollPeriod->locked_at !== null => Carbon::parse($payrollPeriod->locked_at)->timezone(DataPeriod::TIMEZONE),
            default => DataPeriod::today(),
        };

        return $date->format('d').' '.DataPeriod::label($date->format('Y-m'));
    }

    /** Rupiah tanpa desimal, mis. 1.250.000. */
    public static function rupiah(int|float $amount): string
    {
        return number_format($amount, 0, ',', '.');
    }

    /**
     * Posisi kolom blok tanda tangan di Excel: bagian dibagi rata selebar halaman, orang di dalam bagian
     * dibagi rata selebar bagiannya. Nomor kolom mulai 1.
     *
     * @param  list<array{label: string, signers: list<array{name: string, position: string}>}>  $sections
     * @return list<array{label: string, from: int, to: int, signers: list<array{name: string, position: string, from: int, to: int}>}>
     */
    public static function signatureColumns(array $sections, int $totalColumns): array
    {
        $result = [];
        $sectionCount = max(count($sections), 1);
        $column = 1;

        foreach (array_values($sections) as $index => $section) {
            $span = intdiv($totalColumns, $sectionCount) + ($index < $totalColumns % $sectionCount ? 1 : 0);
            $signerCount = max(count($section['signers']), 1);
            $signers = [];
            $signerColumn = $column;

            foreach (array_values($section['signers']) as $signerIndex => $signer) {
                $signerSpan = max(intdiv($span, $signerCount) + ($signerIndex < $span % $signerCount ? 1 : 0), 1);
                $signers[] = [...$signer, 'from' => $signerColumn, 'to' => $signerColumn + $signerSpan - 1];
                $signerColumn += $signerSpan;
            }

            $result[] = ['label' => $section['label'], 'from' => $column, 'to' => $column + $span - 1, 'signers' => $signers];
            $column += $span;
        }

        return $result;
    }

    /**
     * Kelompok yang diekspor (group_keys; null = semua, urutan tetap urutan Daftar Upah), berisi HANYA karyawan kategori
     * itu menurut status karyawan SAAT INI. Kelompok yang jadi kosong dilewati. Kunci tidak dikenal -> 422.
     *
     * @param  list<array<string, mixed>>  $groups  hasil PayrollGroups::build
     * @param  list<string>|null  $groupKeys
     * @return list<array<string, mixed>>
     */
    public static function selectGroups(array $groups, ?array $groupKeys, EmployeeCategory $category): array
    {
        if ($groupKeys !== null) {
            $unknown = array_diff($groupKeys, array_column($groups, 'key'));
            if ($unknown !== []) {
                throw ValidationException::withMessages(['group_keys' => 'Kelompok tidak ditemukan di daftar upah periode ini: '.implode(', ', $unknown)]);
            }
            $groups = array_values(array_filter($groups, fn (array $group) => in_array($group['key'], $groupKeys, true)));
        }

        $ids = array_merge(...array_map(fn (array $group) => array_column($group['rows'], 'employee_id'), $groups ?: [['rows' => []]]));
        $statuses = Employee::query()->whereKey($ids)->pluck('employment_status', 'id');
        $status = $category->employmentStatus()->value;
        $selected = [];
        foreach ($groups as $group) {
            $group['rows'] = array_values(array_filter($group['rows'], fn (array $row) => ($statuses[$row['employee_id']] ?? null) === $status));
            if ($group['rows'] !== []) {
                $group['employee_count'] = count($group['rows']);
                $selected[] = $group;
            }
        }

        return $selected;
    }

    /** 422 "Tidak ada karyawan <kategori> untuk <dokumen> di kelompok yang dipilih". */
    public static function nothingToExport(EmployeeCategory $category, PayrollDocument $document): ValidationException
    {
        return ValidationException::withMessages([
            'document' => "Tidak ada karyawan {$category->shortLabel()} untuk {$document->label()} di kelompok yang dipilih",
        ]);
    }
}
