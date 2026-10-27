<?php

namespace App\Services\Payroll;

use App\Enums\ImportRowStatus;
use App\Enums\WageType;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\PayrollGroup;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * MEMERIKSA baris file impor Skema Upah (tanpa menyimpan apa pun). Untuk tiap baris hasilnya:
 *
 *   status   "berubah" / "tidak berubah" / "salah"
 *   errors   [judul kolom => pesan], mis. ["Gaji Pokok" => "Gaji Pokok harus lebih dari 0"]
 *   changes  bagian yang berubah: { wage_scheme, bank_account, cost_center } (true/false)
 *   records  catatan baru yang akan disimpan (dipakai WageSchemeImporter, tidak dikirim ke front end)
 *
 * Aturan:
 *   - karyawan dicari dari ID Sistem, lalu No. Registrasi; satu karyawan hanya boleh muncul sekali
 *   - skema upah (aturan WageSchemeRules) hanya untuk Casual & Daily Worker
 *   - rekening harus lengkap (Nama Bank, No. Rekening angka saja, Atas Nama)
 *   - kelompok & cost center harus dikenal
 *   - "berubah" = berbeda dengan data yang berlaku pada tanggal Berlaku Mulai; kolom kosong = tidak diubah
 *   - Berlaku Mulai wajib (format tanggal) kalau ada yang berubah
 *   - skema upah yang berubah tidak boleh berlaku ≤ akhir periode upah terkunci
 */
class WageSchemeImportChecker
{
    /** @var Collection<string, Employee> */
    private Collection $employeesById;

    /** @var array<string, Employee> No. Registrasi => karyawan */
    private array $employeesByRegistration = [];

    /** @var array<string, string> nama kelompok (huruf kecil) => id */
    private array $payrollGroupIds = [];

    /** @var array<string, string> kode cost center (huruf besar) => id */
    private array $costCenterIds = [];

    /** Periode upah terkunci paling akhir, atau null. */
    private ?string $latestLockedPeriod = null;

    /**
     * @param  list<array{row: int, values: array<string, mixed>}>  $rows  dari WageSchemeImportReader
     * @return list<array<string, mixed>>
     */
    public function check(array $rows): array
    {
        $this->loadReferences();

        $results = [];
        $seenRows = [];   // id karyawan => nomor baris pertama

        foreach ($rows as $row) {
            $result = $this->checkRow($row['row'], $row['values']);

            $employeeId = $result['employee_id'];
            if ($employeeId !== null) {
                if (isset($seenRows[$employeeId])) {
                    $result['errors'][WageSchemeTemplateColumns::header('registration_number')] ??= "Baris kembar: karyawan ini sudah ada di baris {$seenRows[$employeeId]}";
                    $result['status'] = ImportRowStatus::Invalid->value;
                } else {
                    $seenRows[$employeeId] = $row['row'];
                }
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Ringkasan hasil pemeriksaan: jumlah baris per status.
     *
     * @param  list<array<string, mixed>>  $results
     * @return array{total_rows: int, changed: int, unchanged: int, invalid: int}
     */
    public static function summary(array $results): array
    {
        $count = fn (ImportRowStatus $status) => count(array_filter($results, fn (array $result) => $result['status'] === $status->value));

        return [
            'total_rows' => count($results),
            'changed' => $count(ImportRowStatus::Changed),
            'unchanged' => $count(ImportRowStatus::Unchanged),
            'invalid' => $count(ImportRowStatus::Invalid),
        ];
    }

    // -------------------------------------------------------------------------
    // Satu baris
    // -------------------------------------------------------------------------

    /** @param  array<string, mixed>  $values */
    private function checkRow(int $rowNumber, array $values): array
    {
        $errors = [];
        $employee = $this->findEmployee($values, $errors);

        $effectiveDate = $this->parseDate($values['effective_date']);
        if ($effectiveDate === false) {
            $errors[WageSchemeTemplateColumns::header('effective_date')] = 'Berlaku Mulai harus berupa tanggal (DD/MM/YYYY)';
        }
        $compareDate = $effectiveDate ?: Carbon::today();

        $wageScheme = $this->checkWageScheme($values, $employee, $compareDate, $errors);
        $bankAccount = $this->checkBankAccount($values, $employee, $compareDate, $errors);
        $costCenterId = $this->checkCostCenter($values, $employee, $compareDate, $errors);

        $changes = [
            'wage_scheme' => $wageScheme !== null,
            'bank_account' => $bankAccount !== null,
            'cost_center' => $costCenterId !== null,
        ];
        $hasChanges = in_array(true, $changes, true);

        if ($hasChanges && $effectiveDate === null) {
            $errors[WageSchemeTemplateColumns::header('effective_date')] = 'Berlaku Mulai wajib diisi karena ada data yang berubah';
        }
        $lockedMessage = $effectiveDate && $wageScheme !== null
            ? WageSchemeRules::lockedPeriodMessage($effectiveDate, $this->latestLockedPeriod)
            : null;
        if ($lockedMessage !== null) {
            $errors[WageSchemeTemplateColumns::header('effective_date')] = $lockedMessage;
        }

        $status = match (true) {
            $errors !== [] => ImportRowStatus::Invalid,
            $hasChanges => ImportRowStatus::Changed,
            default => ImportRowStatus::Unchanged,
        };

        return [
            'row' => $rowNumber,
            'employee_id' => $employee?->id,
            'registration_number' => $employee?->registration_number ?? $this->text($values['registration_number']),
            'name' => $employee?->name ?? $this->text($values['name']),
            'employment_status' => $employee?->employment_status,
            'status' => $status->value,
            'errors' => $errors,
            'changes' => $changes,
            'records' => [
                'wage_scheme' => $wageScheme,
                'bank_account' => $bankAccount,
                'cost_center_id' => $costCenterId,
                'effective_date' => $effectiveDate ? $effectiveDate->toDateString() : null,
                'notes' => $this->text($values['notes']),
            ],
        ];
    }

    /**
     * Cari karyawan dari ID Sistem, lalu No. Registrasi.
     *
     * @param  array<string, string>  $errors
     */
    private function findEmployee(array $values, array &$errors): ?Employee
    {
        $column = WageSchemeTemplateColumns::header('registration_number');
        $id = $this->text($values['employee_id']);
        $registrationNumber = $this->text($values['registration_number']);

        if ($id !== null) {
            $employee = $this->employeesById->get($id);
            if ($employee === null) {
                $errors[$column] = 'Karyawan tidak ditemukan (ID Sistem tidak dikenal)';
            }

            return $employee;
        }

        if ($registrationNumber === null) {
            $errors[$column] = 'No. Registrasi wajib diisi';

            return null;
        }

        $employee = $this->employeesByRegistration[$registrationNumber] ?? null;
        if ($employee === null) {
            $errors[$column] = "No. Registrasi {$registrationNumber} tidak ditemukan";
        }

        return $employee;
    }

    /**
     * Skema upah baru, atau null kalau kolom skema kosong / tidak berubah / salah.
     *
     * @param  array<string, string>  $errors
     * @return array<string, mixed>|null
     */
    private function checkWageScheme(array $values, ?Employee $employee, Carbon $date, array &$errors): ?array
    {
        $isFilled = collect(WageSchemeTemplateColumns::WAGE_KEYS)->contains(fn (string $key) => $this->isFilled($values[$key]));
        if (! $isFilled || $employee === null) {
            return null;
        }

        if (! $employee->hasDailyWage()) {
            $errors[WageSchemeTemplateColumns::header('wage_type')] = WageSchemeRules::NOT_DAILY_WAGE_MESSAGE;

            return null;
        }

        $scheme = [
            'wage_type' => $this->wageType($values['wage_type']),
            'base_salary' => $this->integer($values['base_salary']),
            'working_days' => $this->integer($values['working_days']),
            'is_capped' => $this->yesNo($values['is_capped']),
            'operational_position_allowance' => $this->integer($values['operational_position_allowance']),
            'incentive' => $this->integer($values['incentive']),
            'has_meal_allowance' => $this->yesNo($values['has_meal_allowance']),
            'meal_allowance_per_day' => $this->integer($values['meal_allowance_per_day']),
            'payroll_group_id' => null,
        ];

        $groupName = $this->text($values['payroll_group']);
        if ($groupName !== null) {
            $scheme['payroll_group_id'] = $this->payrollGroupIds[mb_strtolower($groupName)] ?? null;
            if ($scheme['payroll_group_id'] === null) {
                $errors[WageSchemeTemplateColumns::header('payroll_group')] = "Kelompok Daftar Upah \"{$groupName}\" tidak dikenal";
            }
        }

        // Aturan isian sama dengan tambah skema lewat form (WageSchemeRules); kelompok sudah diperiksa di atas.
        $rules = [...WageSchemeRules::rules($scheme), 'payroll_group_id' => ['nullable']];
        $messages = [
            ...WageSchemeRules::messages(),
            'is_capped.boolean' => 'Dibatasi 20 Hari harus Ya atau Tidak',
            'has_meal_allowance.boolean' => 'Diberi Uang Makan harus Ya atau Tidak',
        ];
        $validator = Validator::make($scheme, $rules, $messages);
        foreach ($validator->errors()->messages() as $field => $fieldMessages) {
            $errors[WageSchemeTemplateColumns::header($this->columnKeyOf($field))] ??= $fieldMessages[0];
        }
        if ($validator->fails() || isset($errors[WageSchemeTemplateColumns::header('payroll_group')])) {
            return null;
        }

        $newScheme = WageSchemeRules::normalize($scheme);
        $current = $employee->wageSchemeOn($date);

        return $current !== null && WageSchemeRules::normalize($current->schemeValues()) === $newScheme ? null : $newScheme;
    }

    /**
     * Rekening baru, atau null kalau kosong / tidak berubah / salah.
     *
     * @param  array<string, string>  $errors
     * @return array{bank_name: string, account_number: string, account_holder: string}|null
     */
    private function checkBankAccount(array $values, ?Employee $employee, Carbon $date, array &$errors): ?array
    {
        $account = [
            'bank_name' => $this->text($values['bank_name']),
            'account_number' => $this->text($values['account_number']),
            'account_holder' => $this->text($values['account_holder']),
        ];

        if (array_filter($account) === []) {
            return null;
        }

        $isValid = true;
        foreach ($account as $key => $value) {
            if ($value === null) {
                $errors[WageSchemeTemplateColumns::header($key)] = WageSchemeTemplateColumns::header($key).' wajib diisi (rekening harus lengkap)';
                $isValid = false;
            }
        }
        if ($account['account_number'] !== null && ! preg_match('/^\d+$/', $account['account_number'])) {
            $errors[WageSchemeTemplateColumns::header('account_number')] = 'No. Rekening hanya boleh berisi angka';
            $isValid = false;
        }

        if (! $isValid || $employee === null) {
            return null;
        }

        $current = $employee->bankAccountOn($date);
        $isSame = $current !== null
            && $current->bank_name === $account['bank_name']
            && $current->account_number === $account['account_number']
            && $current->account_holder === $account['account_holder'];

        return $isSame ? null : $account;
    }

    /**
     * Id cost center baru, atau null kalau kosong / tidak berubah / salah.
     *
     * @param  array<string, string>  $errors
     */
    private function checkCostCenter(array $values, ?Employee $employee, Carbon $date, array &$errors): ?string
    {
        $code = $this->text($values['cost_center_code']);
        if ($code === null) {
            return null;
        }

        $costCenterId = $this->costCenterIds[mb_strtoupper($code)] ?? null;
        if ($costCenterId === null) {
            $errors[WageSchemeTemplateColumns::header('cost_center_code')] = "Kode Cost Center \"{$code}\" tidak dikenal";

            return null;
        }

        if ($employee === null || $employee->costCenterOn($date)?->cost_center_id === $costCenterId) {
            return null;
        }

        return $costCenterId;
    }

    // -------------------------------------------------------------------------
    // Data pembanding (dimuat sekali untuk semua baris)
    // -------------------------------------------------------------------------

    private function loadReferences(): void
    {
        $employees = Employee::query()
            ->with(['wageSchemes', 'bankAccounts', 'costCenterHistories'])
            ->get();

        $this->employeesById = $employees->keyBy('id');
        $this->employeesByRegistration = $employees->whereNotNull('registration_number')->keyBy('registration_number')->all();
        $this->payrollGroupIds = PayrollGroup::query()->pluck('id', 'name')
            ->mapWithKeys(fn (string $id, string $name) => [mb_strtolower($name) => $id])->all();
        $this->costCenterIds = CostCenter::query()->pluck('id', 'code')
            ->mapWithKeys(fn (string $id, string $code) => [mb_strtoupper($code) => $id])->all();
        $this->latestLockedPeriod = WageSchemeRules::latestLockedPeriod();
    }

    // -------------------------------------------------------------------------
    // Membaca nilai sel
    // -------------------------------------------------------------------------

    /** Kunci kolom template untuk isian skema, mis. payroll_group_id -> payroll_group. */
    private function columnKeyOf(string $field): string
    {
        return $field === 'payroll_group_id' ? 'payroll_group' : $field;
    }

    private function isFilled(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    /** Teks dari sel (angka ditulis tanpa desimal, mis. No. Rekening yang tersimpan sebagai angka); kosong = null. */
    private function text(mixed $value): ?string
    {
        if (! $this->isFilled($value)) {
            return null;
        }

        if (is_float($value) && floor($value) === $value) {
            return sprintf('%.0f', $value);
        }

        return trim((string) $value);
    }

    /** Angka bulat dari sel ("2.500.000", "Rp 2500000", 2500000); kosong = null; tidak terbaca = teks aslinya. */
    private function integer(mixed $value): mixed
    {
        if (! $this->isFilled($value)) {
            return null;
        }
        if (is_int($value) || (is_float($value) && floor($value) === $value)) {
            return (int) $value;
        }

        $cleaned = preg_replace('/^rp\.?|[.\s]/i', '', (string) $value);

        return preg_match('/^-?\d+$/', $cleaned) ? (int) $cleaned : (string) $value;
    }

    /** Ya/Tidak dari sel; kosong = null; tidak dikenal = teks aslinya (ditolak oleh aturan boolean). */
    private function yesNo(mixed $value): mixed
    {
        if (! $this->isFilled($value)) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }

        return match (mb_strtolower(trim((string) $value))) {
            'ya', 'y' => true,
            'tidak', 't' => false,
            default => (string) $value,
        };
    }

    /** Jenis upah dari sel, tanpa membedakan huruf besar/kecil; tidak dikenal = teks aslinya. */
    private function wageType(mixed $value): ?string
    {
        $text = $this->text($value);
        if ($text === null) {
            return null;
        }

        foreach (WageType::values() as $wageType) {
            if (mb_strtolower($wageType) === mb_strtolower($text)) {
                return $wageType;
            }
        }

        return $text;
    }

    /**
     * Tanggal dari sel: tanggal Excel, atau teks DD/MM/YYYY / YYYY-MM-DD.
     * null = kosong, false = tidak terbaca.
     */
    private function parseDate(mixed $value): Carbon|false|null
    {
        if (! $this->isFilled($value)) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::parse($value->format('Y-m-d'));
        }
        if (is_numeric($value)) {
            return Carbon::parse(ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d'));
        }

        $text = trim((string) $value);
        foreach (['d/m/Y', 'j/n/Y', 'Y-m-d', 'd-m-Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $text);
            } catch (Throwable) {
                continue;
            }
            if ($date !== null && $date->format($format) === $text) {
                return $date;
            }
        }

        return false;
    }
}
