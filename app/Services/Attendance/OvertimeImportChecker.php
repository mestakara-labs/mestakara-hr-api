<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceImportType;
use App\Enums\EmployeeCategory;
use App\Enums\OvertimeSource;
use App\Enums\OvertimeType;
use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\OvertimeEntry;
use App\Support\ExcelValue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * MEMERIKSA file LEMBUR REGULER / LEMBUR DW (tanpa menyimpan). Per baris lembur:
 *
 *   SALAH       No. Registrasi tidak valid / bukan Casual-DW / kategori lain (unggah di tab kategorinya; Lembur DW hanya
 *               untuk Casual) / tidak aktif pada tanggal itu; tanggal kosong / di luar
 *               periode; jam kosong / format salah; durasi 0; uraian kosong; tumpang tindih dengan baris lain karyawan
 *               & tanggal yang sama di file, dengan lembur JENIS LAIN yang tersimpan, atau dengan lembur jenis SAMA
 *               dari manual / mobile; tanggal berkode I/S/M/C
 *   PERINGATAN  total lembur (reguler + DW) melebihi batas per hari / per minggu; tanggal berkode L atau kosong
 *   DILEWATI    baris yang SAMA PERSIS (karyawan, tanggal, jam mulai & selesai) dengan lembur jenis sama dari manual /
 *               mobile yang tersimpan — template ikut memuat baris itu; data tersimpan tetap dipakai (prioritas
 *               manual > mobile > Excel), baris file tidak disimpan. Status "peringatan", dihitung `unchanged`.
 *
 * Baris salah menolak seluruh unggahan. Lembur tidak punya konflik (`conflicts` selalu kosong).
 *
 * Mengunggah ulang MENGGANTIKAN semua lembur jenis itu bersumber import_excel pada periode itu (Lembur DW: juga yang
 * bersumber rekomendasi_sistem, lihat replacedSources()), untuk semua karyawan KATEGORI ITU termasuk yang tidak ada
 * di file. Lembur karyawan kategori lain tetap ada.
 */
class OvertimeImportChecker
{
    public function __construct(private AttendanceEmployees $attendanceEmployees) {}

    /**
     * @param  list<array{row: int, registration_number: ?string, date: mixed, start_time: mixed, end_time: mixed, description: ?string}>  $fileRows
     * @return array{rows: list<array>, summary: array<string, int>, entries: list<array>, conflicts: list<array>, replaced: int}
     */
    public function check(AttendancePeriod $period, AttendanceImportType $type, EmployeeCategory $category, array $fileRows): array
    {
        $overtimeType = self::overtimeType($type);
        $employees = $this->attendanceEmployees->forPeriod($period, $category);
        $byRegistration = Employee::query()->whereNotNull('registration_number')->get(['id', 'registration_number', 'name', 'employment_status'])->keyBy('registration_number');
        $codes = $this->storedCodes($period);
        [$kept, $replaced] = $this->storedEntries($period, $overtimeType, $category);

        // 1. Periksa isi setiap baris.
        $rows = [];
        $parsed = [];   // index baris => lembur yang terbaca
        foreach ($fileRows as $index => $fileRow) {
            $result = [
                'row' => $fileRow['row'], 'employee_id' => null, 'registration_number' => $fileRow['registration_number'], 'name' => null,
                'status' => 'benar', 'errors' => [], 'warnings' => [], 'conflicts' => [],
            ];
            $entry = $this->parseRow($result, $fileRow, $period, $overtimeType, $category, $employees, $byRegistration);
            if ($entry !== null) {
                $parsed[$index] = $entry;
            }
            $rows[$index] = $result;
        }

        // 2. Tumpang tindih & kode daftar hadir.
        $skipped = [];   // index baris yang sama persis dengan lembur manual / mobile tersimpan
        foreach ($parsed as $index => $entry) {
            foreach ($parsed as $otherIndex => $other) {
                if ($otherIndex < $index && $other['employee_id'] === $entry['employee_id'] && $other['date'] === $entry['date'] && OvertimeRules::overlaps($entry, $other)) {
                    $this->add($rows[$index], 'errors', 'Jam Mulai', "Tumpang tindih dengan baris {$fileRows[$otherIndex]['row']} (".OvertimeRules::label($other).')');
                }
            }

            foreach ($kept[$entry['employee_id']] ?? [] as $stored) {
                if ($stored['date'] !== $entry['date'] || ! OvertimeRules::overlaps($entry, $stored)) {
                    continue;
                }
                if (self::isSameStoredEntry($entry, $stored, $overtimeType)) {
                    $skipped[$index] = true;
                    $this->add($rows[$index], 'warnings', 'Jam Mulai', 'Sama dengan '.self::typeLabel($stored['overtime_type'])." {$stored['source']} tersimpan; baris ini dilewati (data tersimpan tetap dipakai)");

                    continue;
                }
                // Jenis lain (sumber apa pun) atau jenis sama dari manual / mobile: keduanya tidak boleh ditimpa.
                $what = $stored['overtime_type'] !== $overtimeType->value
                    ? self::typeLabel($stored['overtime_type'])
                    : self::typeLabel($stored['overtime_type']).' '.$stored['source'];
                $this->add($rows[$index], 'errors', 'Jam Mulai', "Tumpang tindih dengan {$what} tersimpan (".OvertimeRules::label($stored).')');
            }

            $code = $codes[$entry['employee_id']][$entry['date']] ?? null;
            if ($error = OvertimeRules::dayCodeError($code)) {
                $this->add($rows[$index], 'errors', 'Tanggal', $error);
            } elseif ($warning = OvertimeRules::dayCodeWarning($code)) {
                $this->add($rows[$index], 'warnings', 'Tanggal', $warning);
            }
        }

        // 3. Batas jam per hari / minggu dari baris yang akan disimpan + lembur tersimpan yang tetap ada.
        $rules = OvertimeRules::make();
        $toSave = array_filter($parsed, fn (array $entry, int $index) => $rows[$index]['errors'] === [] && ! isset($skipped[$index]), ARRAY_FILTER_USE_BOTH);
        $perEmployee = [];
        foreach ([...array_values($toSave), ...array_merge([], ...array_values($kept))] as $entry) {
            $perEmployee[$entry['employee_id']][] = $entry;
        }
        foreach ($toSave as $index => $entry) {
            foreach ($rules->limitWarnings($perEmployee[$entry['employee_id']], $entry['date']) as $warning) {
                $this->add($rows[$index], 'warnings', 'Durasi', $warning);
            }
        }

        $rows = array_map(fn (array $row) => $this->finish($row), array_values($rows));

        return [
            'rows' => $rows,
            'summary' => [
                'total_rows' => count($fileRows),
                'invalid' => count(array_filter($rows, fn (array $row) => $row['status'] === 'salah')),
                'warnings' => count(array_filter($rows, fn (array $row) => $row['warnings'] !== [])),
                'conflicts' => 0,
                'will_create' => count($toSave),
                'will_update' => 0,
                'will_delete' => $replaced,
                'unchanged' => count(array_filter(array_keys($skipped), fn (int $index) => $rows[$index]['status'] !== 'salah')),
            ],
            'entries' => array_values($toSave),
            'conflicts' => [],
            'replaced' => $replaced,
        ];
    }

    public static function overtimeType(AttendanceImportType $type): OvertimeType
    {
        return $type === AttendanceImportType::DailyWorkerOvertime ? OvertimeType::DailyWorker : OvertimeType::Regular;
    }

    /**
     * Sumber lembur jenis itu yang DIGANTI seluruhnya oleh unggahan Excel pada periode itu:
     * Lembur Reguler = import_excel; Lembur DW = import_excel & rekomendasi_sistem (saling menggantikan).
     * Manual & mobile tidak pernah dihapus.
     *
     * @return list<OvertimeSource>
     */
    public static function replacedSources(OvertimeType $type): array
    {
        return $type === OvertimeType::DailyWorker ? DwPeriodData::REPLACEABLE_SOURCES : [OvertimeSource::ImportExcel];
    }

    /**
     * Baca & periksa satu baris; hasilnya lembur yang terbaca, atau null kalau isinya salah.
     *
     * @param  Collection<string, PeriodEmployee>  $employees
     * @param  Collection<string, Employee>  $byRegistration
     */
    private function parseRow(array &$result, array $fileRow, AttendancePeriod $period, OvertimeType $overtimeType, EmployeeCategory $category, Collection $employees, Collection $byRegistration): ?array
    {
        $employee = null;
        $registration = $fileRow['registration_number'];
        if ($registration === null) {
            $this->add($result, 'errors', 'No. Registrasi', 'No. Registrasi wajib diisi');
        } elseif (($found = $byRegistration->get($registration)) === null) {
            $this->add($result, 'errors', 'No. Registrasi', "No. Registrasi {$registration} tidak ditemukan");
        } else {
            $result['employee_id'] = $found->id;
            $result['name'] = $found->name;
            $foundCategory = EmployeeCategory::of($found);
            if ($foundCategory === null) {
                $this->add($result, 'errors', 'No. Registrasi', 'Bukan karyawan Casual / Daily Worker');
            } elseif ($overtimeType === OvertimeType::DailyWorker && ! $foundCategory->allowsDw()) {
                $this->add($result, 'errors', 'No. Registrasi', EmployeeCategory::DW_ONLY_CASUAL_MESSAGE);
            } elseif ($foundCategory !== $category) {
                $this->add($result, 'errors', 'No. Registrasi', $foundCategory->wrongTabMessage());
            } elseif (! $employees->has($found->id)) {
                $this->add($result, 'errors', 'No. Registrasi', 'Karyawan tidak aktif dalam periode '.$period->label());
            } else {
                $employee = $employees->get($found->id);
            }
        }

        $date = ExcelValue::date($fileRow['date']);
        if ($date === null) {
            $this->add($result, 'errors', 'Tanggal', 'Tanggal wajib diisi');
        } elseif ($date === false) {
            $this->add($result, 'errors', 'Tanggal', 'Tanggal harus berformat DD/MM/YYYY');
        } elseif (! $period->contains($date)) {
            $this->add($result, 'errors', 'Tanggal', 'Tanggal di luar periode '.$period->label());
        } elseif ($employee !== null && ! $employee->isActiveOn($date->toDateString())) {
            $this->add($result, 'errors', 'Tanggal', 'Karyawan tidak aktif pada tanggal ini');
        }

        $times = [];
        foreach (['start_time' => 'Jam Mulai', 'end_time' => 'Jam Selesai'] as $key => $label) {
            $times[$key] = ExcelValue::time($fileRow[$key]);
            if ($times[$key] === null) {
                $this->add($result, 'errors', $label, "{$label} wajib diisi");
            } elseif ($times[$key] === false) {
                $this->add($result, 'errors', $label, "{$label} harus berformat HH:MM, mis. 17:00");
            }
        }
        if (is_string($times['start_time']) && $times['start_time'] === $times['end_time']) {
            $this->add($result, 'errors', 'Durasi', 'Durasi lembur 0 (jam mulai sama dengan jam selesai)');
        }

        if ($fileRow['description'] === null) {
            $this->add($result, 'errors', 'Uraian Pekerjaan', 'Uraian Pekerjaan wajib diisi');
        }

        if ($result['errors'] !== []) {
            return null;
        }

        return [
            'employee_id' => $employee->employee->id,
            'date' => $date->toDateString(),
            'start_time' => $times['start_time'],
            'end_time' => $times['end_time'],
            'description' => $fileRow['description'],
        ];
    }

    /** @return array<string, array<string, string>> [id karyawan][tanggal] => kode daftar hadir tersimpan */
    private function storedCodes(AttendancePeriod $period): array
    {
        $codes = [];
        AttendanceDay::query()
            ->where('date', '>=', $period->start()->toDateString())
            ->where('date', '<', $period->end()->addDay()->toDateString())
            ->get(['employee_id', 'date', 'status_code'])
            ->each(function (AttendanceDay $day) use (&$codes) {
                $codes[$day->employee_id][$day->date->toDateString()] = $day->status_code->value;
            });

        return $codes;
    }

    /**
     * Lembur tersimpan di minggu-minggu yang menyentuh periode:
     *   kept      = yang TETAP ADA sesudah unggah (jenis lain, sumber mobile/manual, kategori lain, atau di luar periode)
     *   replaced  = jumlah lembur jenis ini milik karyawan kategori itu dari sumber yang diganti (replacedSources)
     *               di dalam periode
     *
     * @return array{0: array<string, list<array>>, 1: int}
     */
    private function storedEntries(AttendancePeriod $period, OvertimeType $type, EmployeeCategory $category): array
    {
        [$from, $to] = OvertimeRules::weekBounds($period);
        $replacedSources = self::replacedSources($type);
        $categoryIds = Employee::query()->where('employment_status', $category->employmentStatus())->pluck('id')->flip();
        $kept = [];
        $replaced = 0;

        OvertimeEntry::query()
            ->where('date', '>=', $from)
            ->where('date', '<', Carbon::parse($to)->addDay()->toDateString())
            ->get(['employee_id', 'date', 'start_time', 'end_time', 'overtime_type', 'source'])
            ->each(function (OvertimeEntry $entry) use (&$kept, &$replaced, $period, $type, $replacedSources, $categoryIds) {
                $isReplaced = $entry->overtime_type === $type
                    && in_array($entry->source, $replacedSources, true)
                    && $categoryIds->has($entry->employee_id)
                    && $period->contains($entry->date);
                if ($isReplaced) {
                    $replaced++;

                    return;
                }
                $kept[$entry->employee_id][] = [
                    'employee_id' => $entry->employee_id,
                    'date' => $entry->date->toDateString(),
                    'start_time' => substr($entry->start_time, 0, 5),
                    'end_time' => substr($entry->end_time, 0, 5),
                    'overtime_type' => $entry->overtime_type->value,
                    'source' => $entry->source->value,
                ];
            });

        return [$kept, $replaced];
    }

    /**
     * Baris file = lembur tersimpan jenis sama dari manual / mobile (yang tidak diganti unggahan), jam persis sama?
     * Jenis lain tetap dianggap tumpang tindih (salah).
     */
    private static function isSameStoredEntry(array $entry, array $stored, OvertimeType $type): bool
    {
        return $stored['overtime_type'] === $type->value
            && in_array($stored['source'], [OvertimeSource::Manual->value, OvertimeSource::Mobile->value], true)
            && $stored['start_time'] === $entry['start_time']
            && $stored['end_time'] === $entry['end_time'];
    }

    private static function typeLabel(string $overtimeType): string
    {
        return $overtimeType === OvertimeType::DailyWorker->value ? 'Lembur DW' : 'Lembur Reguler';
    }

    private function add(array &$result, string $list, string $column, string $message): void
    {
        $result[$list][] = ['column' => $column, 'message' => $message];
    }

    private function finish(array $result): array
    {
        $result['status'] = match (true) {
            $result['errors'] !== [] => 'salah',
            $result['warnings'] !== [] || $result['conflicts'] !== [] => 'peringatan',
            default => 'benar',
        };

        return $result;
    }
}
