<?php

namespace App\Services\Payroll;

use App\Enums\PayrollGroupBy;
use App\Enums\SignatureDocument;
use App\Enums\SignatureOwnerType;

/**
 * ISI DOKUMEN ekspor Daftar Upah — SATU sumber untuk Excel (PayrollExportWorkbook) dan PDF (view payroll.daftar-upah),
 * supaya angka kedua format selalu sama.
 *
 *   semua kelompok  -> sheet per kelompok + REKAP (satu baris per kelompok, penanda tangan Umum) + PAYROLL
 *   satu kelompok   -> sheet kelompok itu + PAYROLL karyawannya
 *
 * Jumlah di sheet kelompok = REKAP = PAYROLL karena semuanya dijumlahkan dari baris yang sama.
 */
class PayrollExportDocument
{
    public const TITLE = 'DAFTAR UPAH KARYAWAN CASUAL & DAILY WORKER';

    public const FILE_PREFIX = 'Daftar Upah Karyawan Casual & DW';

    /**
     * Kolom sheet kelompok, urut kiri ke kanan: [kunci baris, judul atas, judul bawah (null = digabung 2 baris), lebar, rupiah?].
     * Judul atas yang sama berturut-turut digabung menjadi satu (mis. "HARI KERJA" di atas HKE & Absen).
     */
    public const GROUP_COLUMNS = [
        ['no', 'No', null, 5, false],
        ['registration_number', 'Reg', null, 11, false],
        ['name', 'Nama', null, 28, false],
        ['position', 'Jabatan', null, 24, false],
        ['cost_center_code', 'Cost Center', null, 14, false],
        ['cost_center_name', 'Uraian', null, 24, false],
        ['hke', 'HARI KERJA', 'HKE', 6, false],
        ['present_days', 'HARI KERJA', 'Absen', 7, false],
        ['base_salary', 'Gaji Pokok', null, 13, true],
        ['daily_rate', 'Rp. HK/Hari', null, 11, true],
        ['base_pay', 'Gaji Upah', null, 13, true],
        ['overtime_pay', 'Tunjangan', 'Lembur', 12, true],
        ['operational_allowance', 'Tunjangan', 'Operasional & Jabatan', 13, true],
        ['incentive', 'Tunjangan', 'Insentif', 12, true],
        ['meal_allowance', 'Tunjangan', 'Uang Makan', 12, true],
        ['gross', 'Jml. Upah & Tunjangan', null, 14, true],
        ['bpjs', 'Potongan', 'BPJS TK/KES', 12, true],
        ['pph21', 'Potongan', 'PPIP/PPh.Ps.21', 12, true],
        ['unit', 'Potongan', 'Kebun/Unit', 12, true],
        ['deductions_total', 'Jumlah Potongan', null, 13, true],
        ['net', 'Upah di Terima', null, 14, true],
    ];

    /** Kolom sheet REKAP: [kunci, judul, lebar, rupiah?]. */
    public const REKAP_COLUMNS = [
        ['no', 'No', 5, false],
        ['name', 'URAIAN', 28, false],
        ['employee_count', 'Jumlah Karyawan', 10, false],
        ['hke', 'HKE', 7, false],
        ['present_days', 'ABSEN', 7, false],
        ['base_salary', 'Gaji Pokok', 14, true],
        ['daily_rate', 'Rp.HK', 12, true],
        ['base_pay', 'Gaji Upah', 14, true],
        ['overtime_pay', 'Lembur', 13, true],
        ['operational_allowance', 'OPS & JAB', 13, true],
        ['incentive', 'Insentif', 13, true],
        ['meal_allowance', 'Uang Makan', 13, true],
        ['gross', 'Jml Upah', 14, true],
        ['bpjs', 'BPJS', 12, true],
        ['pph21', 'PPIP', 12, true],
        ['unit', 'Kebun/Unit', 12, true],
        ['deductions_total', 'Jumlah Potongan', 13, true],
        ['net', 'Upah di terima', 14, true],
    ];

    /** Kolom sheet PAYROLL: [kunci, judul, lebar, rupiah?]. */
    public const PAYROLL_COLUMNS = [
        ['no', 'No', 5, false],
        ['registration_number', 'Reg', 11, false],
        ['name', 'Nama', 28, false],
        ['account_number', 'No Rek', 18, false],
        ['bank_name', 'Bank', 14, false],
        ['account_name', 'Atas Nama', 26, false],
        ['base_pay', 'Gaji', 14, true],
        ['allowances', 'Tunjangan', 14, true],
        ['deductions_total', 'Potongan', 13, true],
        ['net', 'Jumlah', 14, true],
        ['cost_center_code', 'Cost Center', 14, false],
    ];

    /** Kolom angka yang dijumlahkan di baris JUMLAH. */
    private const SUM_KEYS = [
        'hke', 'present_days', 'base_salary', 'daily_rate', 'base_pay', 'overtime_pay', 'operational_allowance',
        'incentive', 'meal_allowance', 'gross', 'bpjs', 'pph21', 'unit', 'deductions_total', 'net', 'allowances',
        'employee_count',
    ];

    /**
     * file_name: fungsi (ekstensi) => nama file lengkap.
     *
     * @return array{file_name: callable, is_draft: bool, month_label: string, sheets: list<array<string, mixed>>}
     */
    public function build(PayrollData $data, PayrollGroupBy $groupBy, ?string $groupKey): array
    {
        $allGroups = PayrollGroups::build($data->rows, $groupBy, $data->signatures);
        $groups = PayrollExportText::selectGroups($allGroups, $groupKey);
        $monthLabel = $data->period->label();
        $signDate = PayrollExportText::signDate($data->period->period);
        $usedTitles = [];
        $sheets = [];

        foreach ($groups as $index => $group) {
            $rows = $this->numbered($group['rows']);
            $sheets[] = [
                'type' => 'group',
                'title' => PayrollExportText::sheetTitle($group['name'], $usedTitles),
                'page' => $index + 1,
                'group_label' => PayrollExportText::groupByLabel($groupBy),
                'name' => $group['name'],
                'sub_units' => implode(', ', array_map(
                    fn (array $subUnit) => $subUnit['name'].($subUnit['afdeling_code'] ? " (Kode Afd {$subUnit['afdeling_code']})" : ''),
                    $group['sub_units'],
                )),
                'rows' => $rows,
                'totals' => self::sums($rows),
                'signature' => $this->signature($data->signatures, PayrollGroups::ownerType($groupBy), $group['owner_id'], $signDate),
            ];
        }

        if ($groupKey === null) {
            $rekapRows = array_map(fn (array $group, int $index) => [
                'no' => $index + 1,
                'name' => $group['name'],
                'employee_count' => count($group['rows']),
                ...self::sums($group['rows']),
            ], $groups, array_keys($groups));

            $sheets[] = [
                'type' => 'rekap',
                'title' => PayrollExportText::sheetTitle('REKAP', $usedTitles),
                'page' => count($sheets) + 1,
                'rows' => $rekapRows,
                'totals' => self::sums($rekapRows),
                'signature' => $this->signature($data->signatures, SignatureOwnerType::General, null, $signDate),
            ];
        }

        $payrollRows = $this->numbered(array_merge(...array_map(fn (array $group) => $group['rows'], $groups ?: [['rows' => []]])));
        $sheets[] = [
            'type' => 'payroll',
            'title' => PayrollExportText::sheetTitle('PAYROLL', $usedTitles),
            'page' => count($sheets) + 1,
            'rows' => $payrollRows,
            'totals' => self::sums($payrollRows),
        ];

        $groupName = $groupKey === null ? null : $groups[0]['name'];

        return [
            'is_draft' => ! $data->locked,
            'month_label' => $monthLabel,
            'file_name' => fn (string $extension) => PayrollExportText::fileName(self::FILE_PREFIX, $groupBy, $groupName, $monthLabel, ! $data->locked, $extension),
            'sheets' => $sheets,
        ];
    }

    /**
     * Jumlah kolom angka beberapa baris (kolom yang tidak ada di baris dilewati).
     *
     * @return array<string, int>
     */
    public static function sums(array $rows): array
    {
        $sums = [];
        foreach (self::SUM_KEYS as $key) {
            if ($rows !== [] && array_key_exists($key, $rows[0])) {
                $sums[$key] = (int) array_sum(array_column($rows, $key));
            }
        }

        return $sums;
    }

    /** Baris diberi nomor urut & kolom Tunjangan (Jumlah − Gaji Upah) untuk sheet PAYROLL. */
    private function numbered(array $rows): array
    {
        return array_map(fn (array $row, int $index) => [
            ...$row,
            'no' => $index + 1,
            'allowances' => $row['gross'] - $row['base_pay'],
        ], $rows, array_keys($rows));
    }

    /**
     * Blok tanda tangan: "<tempat>, <tanggal>" + bagian-bagian (label + orang berdampingan).
     *
     * @return array{place_date: string, sections: list<array{label: string, signers: list<array{name: string, position: string}>}>}
     */
    private function signature(SignatureBook $book, SignatureOwnerType $ownerType, ?string $ownerId, string $signDate): array
    {
        $resolved = $book->resolve($ownerType, $ownerId, SignatureDocument::Payroll);

        return ['place_date' => "{$resolved['place']}, {$signDate}", 'sections' => $resolved['sections']];
    }
}
