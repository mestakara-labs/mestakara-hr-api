<?php

namespace App\Services\Payroll;

use App\Enums\EmployeeCategory;
use App\Enums\PayrollDocument;
use App\Enums\PayrollGroupBy;
use App\Enums\SignatureDocument;
use App\Enums\SignatureOwnerType;

/**
 * ISI DOKUMEN ekspor Daftar Upah & Payroll — SATU sumber untuk Excel (PayrollExportWorkbook) dan PDF (view
 * payroll.daftar-upah), supaya angka kedua format selalu sama. Hanya karyawan kategori yang dipilih.
 *
 *   sheet pertama   REKAP: kelompok terpilih ditumpuk, satu baris per kelompok + JUMLAH, penanda tangan Umum
 *   lalu            satu sheet per kelompok berisi rincian per karyawan (penanda tangan kelompok), dipecah per OBJEK
 *                   PENEMPATAN (sections): judul objek, karyawannya (nomor mulai 1 lagi), baris JUMLAH objek; karyawan
 *                   tanpa objek di "LAINNYA" (paling akhir); di bawahnya baris JUMLAH TOTAL seluruh kelompok
 *
 *   daftar_upah   REKAP: jumlah karyawan, gaji, lembur, tunjangan, uang makan, jumlah, potongan, diterima
 *                 rincian: susunan DU "Gunung Mas" (GROUP_COLUMNS)
 *   payroll       REKAP: jumlah karyawan, total transfer, jumlah karyawan tanpa rekening
 *                 rincian: No, Nama, No. Reg, Bank, No. Rekening, Atas Nama, Jumlah Diterima
 *
 * Jumlah di REKAP = jumlah sheet kelompok karena semuanya dijumlahkan dari baris yang sama.
 * SPKL disusun SpklDocument (memakai rekap() di sini).
 */
class PayrollExportDocument
{
    public const TITLE = 'DAFTAR UPAH KARYAWAN CASUAL & DAILY WORKER';

    /** Nama bagian untuk karyawan yang tidak punya objek penempatan. */
    public const OTHER_OBJECT = 'LAINNYA';

    /** Label baris jumlah per objek & jumlah seluruh kelompok. */
    public const SECTION_TOTAL_LABEL = 'JUMLAH';

    public const GRAND_TOTAL_LABEL = 'JUMLAH TOTAL';

    /**
     * Kolom sheet kelompok, urut kiri ke kanan: [kunci baris, judul atas, judul bawah (null = digabung 2 baris), lebar, rupiah?].
     * Judul atas yang sama berturut-turut digabung menjadi satu (mis. "HARI KERJA" di atas HKE & Absen).
     * "\n" di judul = pindah baris (mis. "PPIP\n/PPh.Ps.21").
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
        ['pph21', 'Potongan', "PPIP\n/PPh.Ps.21", 12, true],
        ['unit', 'Potongan', "Kebun\n/Unit", 12, true],
        ['deductions_total', 'Jumlah Potongan', null, 13, true],
        ['net', 'Upah di Terima', null, 14, true],
    ];

    /** Jumlah kolom identitas di depan sheet kelompok (No s.d. Uraian), digabung untuk tulisan JUMLAH. */
    public const GROUP_LABEL_COLUMNS = 6;

    /** Kolom nama orang (karyawan & pemegang rekening): sempit, berlipat, rata kiri. */
    public const NAME_KEYS = ['name', 'account_name'];

    /**
     * Kolom tabel satu tingkat (REKAP & rincian Payroll): [kunci, judul, lebar, format]. format: true = rupiah,
     * 'hours' = jam (0 = "-"), false = apa adanya.
     */
    public const DAFTAR_UPAH_REKAP_COLUMNS = [
        ['no', 'No', 5, false],
        ['name', 'URAIAN', 28, false],
        ['employee_count', 'Jumlah Karyawan', 10, false],
        ['base_pay', 'Gaji', 14, true],
        ['overtime_pay', 'Lembur', 13, true],
        ['allowances', 'Tunjangan', 13, true],
        ['meal_allowance', 'Uang Makan', 13, true],
        ['gross', 'Jumlah', 14, true],
        ['deductions_total', 'Potongan', 13, true],
        ['net', 'Diterima', 14, true],
    ];

    public const PAYROLL_REKAP_COLUMNS = [
        ['no', 'No', 5, false],
        ['name', 'URAIAN', 28, false],
        ['employee_count', 'Jumlah Karyawan', 10, false],
        ['net', 'Total Transfer', 16, true],
        ['without_account', 'Tanpa Rekening', 10, false],
    ];

    /** Rincian Payroll per kelompok. */
    public const PAYROLL_COLUMNS = [
        ['no', 'No', 5, false],
        ['name', 'Nama', 28, false],
        ['registration_number', 'No. Reg', 11, false],
        ['bank_name', 'Bank', 12, false],
        ['account_number', 'No. Rekening', 18, false],
        ['account_name', 'Atas Nama', 26, false],
        ['net', 'Jumlah Diterima', 16, true],
    ];

    /** Kolom angka yang dijumlahkan di baris JUMLAH; jam dijumlah sebagai pecahan, sisanya bilangan bulat. */
    private const SUM_KEYS = [
        'hke', 'present_days', 'base_salary', 'daily_rate', 'base_pay', 'overtime_pay', 'operational_allowance',
        'incentive', 'meal_allowance', 'gross', 'bpjs', 'pph21', 'unit', 'deductions_total', 'net', 'allowances',
        'employee_count', 'without_account', 'total_hours', 'paid_hours', 'pay',
    ];

    private const HOUR_KEYS = ['total_hours', 'paid_hours'];

    /**
     * Dokumen daftar_upah / payroll. file_name: fungsi (ekstensi) => nama file lengkap.
     *
     * @param  list<string>|null  $groupKeys  null = semua kelompok
     * @return array{file_name: callable, is_draft: bool, month_label: string, sheets: list<array<string, mixed>>}
     */
    public function build(PayrollData $data, PayrollDocument $document, PayrollGroupBy $groupBy, ?array $groupKeys, EmployeeCategory $category, PayrollExportOptions $options = new PayrollExportOptions): array
    {
        $groups = PayrollExportText::selectGroups(PayrollGroups::build($data->rows, $groupBy, $data->signatures), $groupKeys, $category);
        if ($groups === []) {
            throw PayrollExportText::nothingToExport($category, $document);
        }

        $signDate = $options->signDateText($data->period->period);
        $isDraft = $options->isDraft($data);
        $usedTitles = [];
        $isPayroll = $document === PayrollDocument::Payroll;
        $title = $isPayroll ? 'PAYROLL '.self::TITLE : self::TITLE;

        $rekapRows = array_map(fn (array $group) => $isPayroll
            ? [
                'name' => $group['name'],
                'employee_count' => count($group['rows']),
                'net' => (int) array_sum(array_column($group['rows'], 'net')),
                'without_account' => count(array_filter($group['rows'], fn (array $row) => blank($row['account_number']))),
            ]
            : [
                'name' => $group['name'],
                'employee_count' => count($group['rows']),
                ...self::sums($group['rows']),
                'allowances' => (int) array_sum(array_map(fn (array $row) => $row['operational_allowance'] + $row['incentive'], $group['rows'])),
            ], $groups);

        $sheets = [self::rekap(
            $usedTitles,
            "REKAPITULASI {$title}",
            $isPayroll ? self::PAYROLL_REKAP_COLUMNS : self::DAFTAR_UPAH_REKAP_COLUMNS,
            $rekapRows,
            $this->signature($data->signatures, SignatureOwnerType::General, null, SignatureDocument::Payroll, $signDate),
        )];

        foreach ($groups as $index => $group) {
            $sections = self::sections($group['rows']);
            $rows = array_merge(...array_column($sections, 'rows'));
            $sheets[] = [
                'type' => $isPayroll ? 'list' : 'group',
                'heading' => $title,
                'title' => PayrollExportText::sheetTitle($group['name'], $usedTitles),
                'page' => $index + 2,
                'group_label' => PayrollExportText::groupByLabel($groupBy),
                'name' => $group['name'],
                'sub_units' => implode(', ', array_map(
                    fn (array $subUnit) => $subUnit['name'].($subUnit['afdeling_code'] ? " (Kode Afd {$subUnit['afdeling_code']})" : ''),
                    $group['sub_units'],
                )),
                'columns' => $isPayroll ? self::PAYROLL_COLUMNS : null,
                'label_span' => $isPayroll ? 6 : self::GROUP_LABEL_COLUMNS,
                'sections' => $sections,
                'rows' => $rows,
                'totals' => self::sums($rows),
                'signature' => $this->signature($data->signatures, PayrollGroups::ownerType($groupBy), $group['owner_id'], SignatureDocument::Payroll, $signDate),
            ];
        }

        $monthLabel = $data->period->label();
        $groupNames = array_column($groups, 'name');

        return [
            'is_draft' => $isDraft,
            'month_label' => $monthLabel,
            'file_name' => fn (string $extension) => PayrollExportText::fileName($document, $category, $groupNames, $monthLabel, $isDraft, $extension),
            'sheets' => $sheets,
        ];
    }

    /**
     * Sheet REKAP: satu baris per kelompok (diberi nomor) + JUMLAH.
     *
     * @param  array<string, true>  $usedTitles
     * @param  list<array{0: string, 1: string, 2: int, 3: bool|string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    public static function rekap(array &$usedTitles, string $heading, array $columns, array $rows, array $signature): array
    {
        $rows = array_map(fn (array $row, int $index) => ['no' => $index + 1, ...$row], $rows, array_keys($rows));

        return [
            'type' => 'rekap',
            'heading' => $heading,
            'title' => PayrollExportText::sheetTitle('REKAP', $usedTitles),
            'page' => 1,
            'columns' => $columns,
            'label_span' => 2,
            'rows' => $rows,
            'totals' => self::sums($rows),
            'signature' => $signature,
        ];
    }

    /**
     * Jumlah kolom angka beberapa baris (kolom yang tidak ada di baris dilewati).
     *
     * @return array<string, int|float>
     */
    public static function sums(array $rows): array
    {
        $sums = [];
        foreach (self::SUM_KEYS as $key) {
            if ($rows !== [] && array_key_exists($key, $rows[0])) {
                $total = array_sum(array_column($rows, $key));
                $sums[$key] = in_array($key, self::HOUR_KEYS, true) ? round($total, 2) : (int) $total;
            }
        }

        return $sums;
    }

    /**
     * Baris satu kelompok dipecah per objek penempatan (urut nama objek, "LAINNYA" paling akhir). Urutan karyawan di
     * dalam objek tetap; nomor urut mulai 1 lagi di setiap objek.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{name: string, rows: list<array<string, mixed>>, totals: array<string, int|float>}>
     */
    public static function sections(array $rows): array
    {
        $byObject = [];
        foreach ($rows as $row) {
            $byObject[$row['placement_object_name'] ?? self::OTHER_OBJECT][] = $row;
        }
        // Kunci array bisa berubah jadi angka (nama objek "123"), jadi dibandingkan sebagai teks.
        uksort($byObject, fn (int|string $a, int|string $b) => (((string) $a === self::OTHER_OBJECT) <=> ((string) $b === self::OTHER_OBJECT)) ?: strnatcasecmp((string) $a, (string) $b));

        $sections = [];
        foreach ($byObject as $name => $objectRows) {
            $numbered = self::numbered($objectRows);
            $sections[] = ['name' => (string) $name, 'rows' => $numbered, 'totals' => self::sums($numbered)];
        }

        return $sections;
    }

    /** Baris diberi nomor urut. */
    private static function numbered(array $rows): array
    {
        return array_map(fn (array $row, int $index) => [...$row, 'no' => $index + 1], $rows, array_keys($rows));
    }

    /**
     * Blok tanda tangan: "<tempat>, <tanggal>" + bagian-bagian (label + orang berdampingan).
     *
     * @return array{place_date: string, sections: list<array{label: string, signers: list<array{name: string, position: string}>}>}
     */
    public function signature(SignatureBook $book, SignatureOwnerType $ownerType, ?string $ownerId, SignatureDocument $document, string $signDate): array
    {
        $resolved = $book->resolve($ownerType, $ownerId, $document);

        return ['place_date' => "{$resolved['place']}, {$signDate}", 'sections' => $resolved['sections']];
    }
}
