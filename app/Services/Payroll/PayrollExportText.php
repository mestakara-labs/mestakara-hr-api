<?php

namespace App\Services\Payroll;

use App\Enums\PayrollGroupBy;
use App\Enums\PayrollPeriodStatus;
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
     * Nama file, mis. "Daftar Upah Karyawan Casual & DW - Kelompok Daftar Upah - Gunung Mas - Oktober 2026 (DRAF).xlsx".
     * Karakter yang tidak boleh di nama file (/ \ : * ? " < > |) diganti "-".
     */
    public static function fileName(string $prefix, PayrollGroupBy $groupBy, ?string $groupName, string $monthLabel, bool $isDraft, string $extension): string
    {
        $parts = [$prefix, self::groupByLabel($groupBy)];
        if ($groupName !== null) {
            $parts[] = $groupName;
        }
        $parts[] = $monthLabel;

        $name = implode(' - ', $parts).($isDraft ? ' (DRAF)' : '');

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

    /** Tanggal tanda tangan: tanggal kunci (WIB) kalau periode dikunci, selain itu hari ini. Mis. "02 Oktober 2026". */
    public static function signDate(string $period): string
    {
        $payrollPeriod = PayrollPeriod::query()->where('period', $period)->first();
        $date = $payrollPeriod?->status === PayrollPeriodStatus::Locked && $payrollPeriod->locked_at !== null
            ? Carbon::parse($payrollPeriod->locked_at)->timezone(DataPeriod::TIMEZONE)
            : DataPeriod::today();

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
     * Kelompok yang diekspor: semua, atau satu kelompok (group_key). Kunci tidak dikenal -> 422.
     *
     * @param  list<array<string, mixed>>  $groups  hasil PayrollGroups::build
     * @return list<array<string, mixed>>
     */
    public static function selectGroups(array $groups, ?string $groupKey): array
    {
        if ($groupKey === null) {
            return $groups;
        }

        $selected = array_values(array_filter($groups, fn (array $group) => $group['key'] === $groupKey));
        if ($selected === []) {
            throw ValidationException::withMessages(['group_key' => 'Kelompok tidak ditemukan di daftar upah periode ini']);
        }

        return $selected;
    }
}
