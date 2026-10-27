<?php

namespace App\Services\Attendance;

use App\Enums\OvertimeChangeAction;
use App\Enums\OvertimeSource;
use App\Enums\OvertimeType;
use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\OvertimeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * UBAH LEMBUR PER BARIS oleh admin (Lembur Reguler & Lembur DW). Hasilnya selalu bersumber "manual" — baris
 * Excel / rekomendasi yang diubah ikut menjadi manual, jadi tidak lagi terhapus oleh unggahan / rekomendasi.
 *
 *   422  periode dikunci; karyawan bukan Casual/DW atau tidak aktif pada tanggal itu; tanggal di luar periode;
 *        durasi 0; tumpang tindih dengan lembur lain karyawan itu (reguler maupun DW); tanggal berkode I/S/M/C
 *   peringatan (tidak menolak, terlihat di `warnings` baris): melebihi batas jam per hari / minggu; tanggal L / kosong
 *
 * Setiap perubahan dicatat di jejak (OvertimeTrail), termasuk alasan.
 */
class OvertimeEntryService
{
    public function __construct(private AttendanceEmployees $attendanceEmployees) {}

    /** @param  array{employee_id: string, overtime_type: string, date: string, start_time: string, end_time: string, description: string, reason: string}  $input */
    public function create(AttendancePeriod $period, array $input): OvertimeEntry
    {
        $employee = Employee::findOrFail($input['employee_id']);
        if (! $period->contains($input['date'])) {
            throw ValidationException::withMessages(['date' => 'Tanggal di luar periode '.$period->label()]);
        }
        $this->validate($employee, $input, null);

        return DB::transaction(function () use ($input) {
            $entry = OvertimeEntry::create([
                'employee_id' => $input['employee_id'],
                'date' => $input['date'],
                'start_time' => $input['start_time'],
                'end_time' => $input['end_time'],
                'duration_minutes' => OvertimeRules::minutes($input['start_time'], $input['end_time']),
                'overtime_type' => $input['overtime_type'],
                'description' => $input['description'],
                'source' => OvertimeSource::Manual,
                'created_by' => auth()->id(),
            ]);
            OvertimeTrail::record($entry, OvertimeChangeAction::Created, OvertimeSource::Manual, $input['reason'], [], OvertimeTrail::values($entry));

            return $entry;
        });
    }

    /** @param  array{date: string, start_time: string, end_time: string, description: string, reason: string}  $input */
    public function update(OvertimeEntry $entry, array $input): OvertimeEntry
    {
        $this->ensureNotLocked(new AttendancePeriod($entry->date->format('Y-m')));
        $this->validate($entry->employee, [...$input, 'overtime_type' => $entry->overtime_type->value], $entry);

        return DB::transaction(function () use ($entry, $input) {
            $old = OvertimeTrail::values($entry);
            $entry->update([
                'date' => $input['date'],
                'start_time' => $input['start_time'],
                'end_time' => $input['end_time'],
                'duration_minutes' => OvertimeRules::minutes($input['start_time'], $input['end_time']),
                'description' => $input['description'],
                'source' => OvertimeSource::Manual,
                'import_batch_id' => null,
            ]);
            OvertimeTrail::record($entry->refresh(), OvertimeChangeAction::Updated, OvertimeSource::Manual, $input['reason'], $old, OvertimeTrail::values($entry));

            return $entry;
        });
    }

    public function delete(OvertimeEntry $entry, string $reason): void
    {
        $this->ensureNotLocked(new AttendancePeriod($entry->date->format('Y-m')));

        DB::transaction(function () use ($entry, $reason) {
            OvertimeTrail::record($entry, OvertimeChangeAction::Deleted, OvertimeSource::Manual, $reason, OvertimeTrail::values($entry), []);
            $entry->delete();
        });
    }

    /** Aturan yang menolak (422). $current = baris yang sedang diubah (dikecualikan dari tumpang tindih). */
    private function validate(Employee $employee, array $input, ?OvertimeEntry $current): void
    {
        $period = new AttendancePeriod(substr($input['date'], 0, 7));
        $this->ensureNotLocked($period);

        if (! $employee->hasDailyWage()) {
            throw ValidationException::withMessages(['employee_id' => 'Lembur hanya untuk karyawan Casual & Daily Worker']);
        }
        if (! $this->attendanceEmployees->one($employee, $period)->isActiveOn($input['date'])) {
            throw ValidationException::withMessages(['date' => 'Karyawan tidak aktif pada tanggal '.Carbon::parse($input['date'])->format('d/m/Y')]);
        }
        if ($input['start_time'] === $input['end_time']) {
            throw ValidationException::withMessages(['end_time' => 'Durasi lembur 0 (jam mulai sama dengan jam selesai)']);
        }

        $others = OvertimeEntry::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $input['date'])
            ->when($current !== null, fn ($query) => $query->whereKeyNot($current->id))
            ->get();
        foreach ($others as $other) {
            $otherArray = DwPeriodData::entryArray($other);
            if (OvertimeRules::overlaps($input, $otherArray)) {
                $label = $other->overtime_type === OvertimeType::DailyWorker ? 'Lembur DW' : 'Lembur Reguler';
                throw ValidationException::withMessages(['start_time' => "Tumpang tindih dengan {$label} ".OvertimeRules::label($otherArray)]);
            }
        }

        $code = AttendanceDay::query()->where('employee_id', $employee->id)->whereDate('date', $input['date'])->first()?->status_code->value;
        if ($error = OvertimeRules::dayCodeError($code)) {
            throw ValidationException::withMessages(['date' => $error]);
        }
    }

    private function ensureNotLocked(AttendancePeriod $period): void
    {
        if ($period->isLocked()) {
            throw ValidationException::withMessages(['period' => "Periode upah {$period->label()} sudah dikunci. Lembur bulan itu tidak bisa diubah."]);
        }
    }
}
