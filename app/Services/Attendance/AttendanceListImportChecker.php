<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceCode;
use App\Enums\AttendanceSource;
use App\Models\AttendanceDay;
use App\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * MEMERIKSA file DAFTAR HADIR (tanpa menyimpan). Hasilnya per baris: status "benar" / "peringatan" / "salah",
 * errors, warnings, conflicts; ditambah RENCANA perubahan per tanggal yang dipakai AttendanceImportService.
 *
 *   SALAH       No. Registrasi tidak ada / bukan Casual-DW / tidak aktif dalam periode; kode di luar daftar;
 *               kode pada tanggal ketika karyawan tidak aktif; baris kembar (karyawan sama)
 *   PERINGATAN  jumlah K melebihi batas hari skema upah; karyawan aktif yang tidak ada di file
 *               (data unggahannya ikut dikosongkan)
 *   KONFLIK     tanggal tersimpan dari mobile / manual yang kodenya berbeda dengan file -> tidak ditimpa
 *
 * Mengunggah ulang MENGGANTIKAN data bersumber import_excel: tanggal yang kosong di file (atau karyawan yang tidak
 * ada di file) dan tersimpan dari import_excel akan dikosongkan.
 */
class AttendanceListImportChecker
{
    public function __construct(private AttendanceEmployees $attendanceEmployees) {}

    /**
     * @param  list<array{row: int, employee_id: ?string, registration_number: ?string, name: ?string, codes: array<string, ?string>}>  $fileRows
     * @return array{rows: list<array>, summary: array<string, int>, changes: list<array{employee_id: string, date: string, code: ?string, action: string}>, conflicts: list<array>, unchanged: int}
     */
    public function check(AttendancePeriod $period, array $fileRows): array
    {
        $employees = $this->attendanceEmployees->forPeriod($period);
        $stored = $this->storedDays($period);
        $allEmployees = Employee::all(['id', 'registration_number', 'name', 'employment_status']);
        $byId = $allEmployees->keyBy('id');
        $byRegistration = $allEmployees->whereNotNull('registration_number')->keyBy('registration_number');

        $rows = [];
        $changes = [];
        $conflicts = [];
        $unchanged = 0;
        $seen = [];

        foreach ($fileRows as $fileRow) {
            $result = $this->emptyResult($fileRow['row'], $fileRow['registration_number'], $fileRow['name']);
            $employee = ($fileRow['employee_id'] !== null ? $byId->get($fileRow['employee_id']) : null)
                ?? ($fileRow['registration_number'] !== null ? $byRegistration->get($fileRow['registration_number']) : null);

            if ($employee === null) {
                $this->error($result, 'No. Registrasi', $fileRow['registration_number'] === null
                    ? 'No. Registrasi wajib diisi'
                    : "No. Registrasi {$fileRow['registration_number']} tidak ditemukan");
            } else {
                $result['employee_id'] = $employee->id;
                $result['registration_number'] = $employee->registration_number;
                $result['name'] = $employee->name;

                if (! $employee->hasDailyWage()) {
                    $this->error($result, 'No. Registrasi', 'Bukan karyawan Casual / Daily Worker');
                } elseif (! $employees->has($employee->id)) {
                    $this->error($result, 'No. Registrasi', 'Karyawan tidak aktif dalam periode '.$period->label());
                } elseif (isset($seen[$employee->id])) {
                    $this->error($result, 'No. Registrasi', "Baris kembar: karyawan ini sudah ada di baris {$seen[$employee->id]}");
                } else {
                    $seen[$employee->id] = $fileRow['row'];
                }
            }

            $item = $result['errors'] === [] ? $employees->get($employee->id) : null;
            $codes = $this->checkCodes($result, $fileRow['codes'], $item);

            if ($result['errors'] === [] && $item !== null) {
                $capDays = $item->capDays();
                $workDays = count(array_keys($codes, AttendanceCode::Work->value, true));
                if ($capDays !== null && $workDays > $capDays) {
                    $this->warning($result, 'K', "Jumlah K ({$workDays}) melebihi batas {$capDays} hari pada skema upah");
                }
                $unchanged += $this->plan($result, $item->employee->id, $codes, $stored[$item->employee->id] ?? [], $changes, $conflicts);
            }

            $rows[] = $this->finish($result);
        }

        // Karyawan aktif yang tidak ada di file: peringatan, dan data unggahannya ikut dikosongkan.
        foreach ($employees as $id => $item) {
            if (isset($seen[$id])) {
                continue;
            }
            $result = $this->emptyResult(null, $item->employee->registration_number, $item->employee->name);
            $result['employee_id'] = $id;
            $this->warning($result, 'No. Registrasi', 'Karyawan aktif ini tidak ada di file; data hasil unggahan sebelumnya akan dikosongkan');
            foreach ($stored[$id] ?? [] as $date => $day) {
                if ($day['source'] === AttendanceSource::ImportExcel->value) {
                    $changes[] = ['employee_id' => $id, 'date' => $date, 'code' => null, 'action' => 'delete'];
                }
            }
            $rows[] = $this->finish($result);
        }

        return [
            'rows' => $rows,
            'summary' => [
                'total_rows' => count($fileRows),
                'invalid' => count(array_filter($rows, fn (array $row) => $row['status'] === 'salah')),
                'warnings' => count(array_filter($rows, fn (array $row) => $row['warnings'] !== [])),
                'conflicts' => count($conflicts),
                'will_create' => count(array_filter($changes, fn (array $change) => $change['action'] === 'create')),
                'will_update' => count(array_filter($changes, fn (array $change) => $change['action'] === 'update')),
                'will_delete' => count(array_filter($changes, fn (array $change) => $change['code'] === null)),
                'unchanged' => $unchanged,
            ],
            'changes' => $changes,
            'conflicts' => $conflicts,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * Periksa kode per tanggal; hasilnya kode yang sudah dirapikan (huruf besar, kosong = null).
     *
     * @param  array<string, ?string>  $rawCodes
     * @return array<string, ?string>
     */
    private function checkCodes(array &$result, array $rawCodes, ?PeriodEmployee $item): array
    {
        $codes = [];
        foreach ($rawCodes as $date => $raw) {
            $code = $raw === null ? null : mb_strtoupper(trim($raw));
            $codes[$date] = $code;
            if ($code === null) {
                continue;
            }

            $day = Carbon::parse($date)->day;
            if (AttendanceCode::tryFrom($code) === null) {
                $this->error($result, "Tgl {$day}", "Kode \"{$raw}\" tidak dikenal (pakai K, DW, I, S, M, C, atau L)");
            } elseif ($item !== null && ! $item->isActiveOn($date)) {
                $this->error($result, "Tgl {$day}", "Karyawan tidak aktif pada tanggal {$day}");
            }
        }

        return $codes;
    }

    /**
     * Bandingkan file dengan yang tersimpan, isi rencana perubahan & konflik. Hasilnya jumlah tanggal yang tidak berubah.
     *
     * @param  array<string, ?string>  $codes
     * @param  array<string, array{code: string, source: string}>  $stored
     */
    private function plan(array &$result, string $employeeId, array $codes, array $stored, array &$changes, array &$conflicts): int
    {
        $unchanged = 0;
        foreach ($codes as $date => $code) {
            $current = $stored[$date] ?? null;

            if ($current === null && $code === null) {
                continue;
            }
            if ($current !== null && $current['code'] === $code) {
                $unchanged++;

                continue;
            }
            if ($current !== null && $current['source'] !== AttendanceSource::ImportExcel->value) {
                $conflict = ['date' => $date, 'current_code' => $current['code'], 'current_source' => $current['source'], 'file_code' => $code];
                $result['conflicts'][] = $conflict;
                $conflicts[] = ['employee_id' => $employeeId, ...$conflict];

                continue;
            }

            $changes[] = [
                'employee_id' => $employeeId,
                'date' => $date,
                'code' => $code,
                'action' => $code === null ? 'delete' : ($current === null ? 'create' : 'update'),
            ];
        }

        return $unchanged;
    }

    /** @return array<string, array<string, array{code: string, source: string}>> [id karyawan][tanggal] */
    private function storedDays(AttendancePeriod $period): array
    {
        $stored = [];
        AttendanceDay::query()
            ->where('date', '>=', $period->start()->toDateString())
            ->where('date', '<', $period->end()->addDay()->toDateString())
            ->get(['employee_id', 'date', 'status_code', 'source'])
            ->each(function (AttendanceDay $day) use (&$stored) {
                $stored[$day->employee_id][$day->date->toDateString()] = ['code' => $day->status_code->value, 'source' => $day->source->value];
            });

        return $stored;
    }

    private function emptyResult(?int $row, ?string $registrationNumber, ?string $name): array
    {
        return [
            'row' => $row, 'employee_id' => null, 'registration_number' => $registrationNumber, 'name' => $name,
            'status' => 'benar', 'errors' => [], 'warnings' => [], 'conflicts' => [],
        ];
    }

    private function error(array &$result, string $column, string $message): void
    {
        $result['errors'][] = ['column' => $column, 'message' => $message];
    }

    private function warning(array &$result, string $column, string $message): void
    {
        $result['warnings'][] = ['column' => $column, 'message' => $message];
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
