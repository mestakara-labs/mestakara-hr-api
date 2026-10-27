<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceSource;
use App\Enums\AttendanceWriteOutcome;
use App\Enums\PayrollPeriodStatus;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayChange;
use App\Models\AttendanceDayChangeItem;
use App\Models\AttendanceImportBatch;
use App\Models\PayrollPeriod;
use BackedEnum;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SATU-SATUNYA pintu untuk menulis absensi harian (attendance_days) — dipakai impor Excel, koreksi admin,
 * dan (nanti) pengolah scan aplikasi mobile. Aturannya, berurutan:
 *
 *   1. Periode upah tanggal itu DIKUNCI           -> tidak diubah                          (Dikunci)
 *   2. Koreksi admin (manual) wajib beralasan      -> 422 kalau alasan kosong
 *   3. Belum ada data hari itu                     -> dibuat                                (Dibuat)
 *   4. Isinya sama dengan yang tersimpan           -> tidak diubah                          (Tidak Berubah)
 *   5. Sumber baru KALAH prioritas dari sumber lama
 *      (manual > mobile > import_excel)            -> TIDAK ditimpa, dilaporkan sebagai     (Konflik)
 *   6. Selain itu                                  -> diubah, sumber hari itu ikut berganti (Diubah)
 *
 * MENGOSONGKAN satu hari (sel kosong = tidak masuk) memakai aturan yang sama: barisnya dihapus (Dihapus);
 * hari yang memang belum ada = Tidak Berubah.
 *
 * Setiap Dibuat / Diubah / Dihapus dicatat di attendance_day_changes + detail per kolom (attendance_day_change_items).
 * Konflik tidak disimpan di sini; pemanggil (mis. impor Excel) yang mencatat / menampilkannya.
 *
 *   write()      satu tanggal
 *   clear()      kosongkan satu tanggal
 *   writeMany()  banyak tanggal sekaligus (impor Excel) — aturan sama; write() & clear() juga memakainya.
 *                Data lama dimuat sekali dan catatan baru disimpan per kelompok, jadi ribuan tanggal tetap cepat.
 */
class AttendanceDayService
{
    /** Banyaknya baris per perintah INSERT massal. */
    private const CHUNK = 500;

    /**
     * Tulis satu tanggal.
     *
     * @param  array<string, mixed>  $values  isian hari itu (kunci di AttendanceDay::DATA_FIELDS; status_code wajib saat dibuat)
     * @param  string|null  $reason  alasan, wajib untuk sumber manual
     */
    public function write(
        string $employeeId,
        Carbon|string $date,
        array $values,
        AttendanceSource $source,
        ?string $reason = null,
        ?AttendanceImportBatch $batch = null,
    ): AttendanceWriteOutcome {
        return $this->writeMany([['employee_id' => $employeeId, 'date' => $date, 'values' => $values]], $source, $reason, $batch)[0];
    }

    /** Kosongkan satu tanggal (hapus barisnya). */
    public function clear(
        string $employeeId,
        Carbon|string $date,
        AttendanceSource $source,
        ?string $reason = null,
        ?AttendanceImportBatch $batch = null,
    ): AttendanceWriteOutcome {
        return $this->writeMany([['employee_id' => $employeeId, 'date' => $date, 'values' => null]], $source, $reason, $batch)[0];
    }

    /**
     * Tulis / kosongkan BANYAK tanggal sekaligus. Hasilnya outcome per item, urutan sama dengan $items.
     *
     * @param  list<array{employee_id: string, date: Carbon|string, values: array<string, mixed>|null}>  $items  values null = kosongkan
     * @return list<AttendanceWriteOutcome>
     */
    public function writeMany(array $items, AttendanceSource $source, ?string $reason = null, ?AttendanceImportBatch $batch = null): array
    {
        $reason = trim((string) $reason) ?: null;
        if ($source === AttendanceSource::Manual && $reason === null) {
            throw ValidationException::withMessages(['reason' => 'Alasan koreksi wajib diisi']);
        }

        if ($items === []) {
            return [];
        }
        $items = array_map(fn (array $item) => [...$item, 'date' => Carbon::parse($item['date'])->toDateString()], $items);

        $lockedPeriods = PayrollPeriod::query()
            ->whereIn('period', array_unique(array_map(fn (array $item) => substr($item['date'], 0, 7), $items)))
            ->where('status', PayrollPeriodStatus::Locked)
            ->pluck('period')
            ->flip();

        return DB::transaction(function () use ($items, $source, $reason, $batch, $lockedPeriods) {
            $existing = $this->existingDays($items);
            $now = now();
            $userId = auth()->id();
            $outcomes = [];
            $newDays = [];
            $changes = [];
            $changeItems = [];
            $deleteIds = [];

            foreach ($items as $item) {
                $key = $item['employee_id'].'|'.$item['date'];
                $day = $existing[$key] ?? null;

                if (isset($lockedPeriods[substr($item['date'], 0, 7)])) {
                    $outcomes[] = AttendanceWriteOutcome::Locked;

                    continue;
                }

                // --- Kosongkan ---
                if ($item['values'] === null) {
                    if ($day === null) {
                        $outcomes[] = AttendanceWriteOutcome::Unchanged;
                    } elseif ($source->priority() < $day->source->priority()) {
                        $outcomes[] = AttendanceWriteOutcome::Conflict;
                    } else {
                        $old = array_filter(Arr::only($day->getRawOriginal(), AttendanceDay::DATA_FIELDS), fn (mixed $value) => $value !== null);
                        $this->queueChange($changes, $changeItems, $day->id, $item, AttendanceWriteOutcome::Deleted, $source, $reason, $batch, $userId, $now, $old, []);
                        $deleteIds[] = $day->id;
                        unset($existing[$key]);
                        $outcomes[] = AttendanceWriteOutcome::Deleted;
                    }

                    continue;
                }

                $values = Arr::only($item['values'], AttendanceDay::DATA_FIELDS);

                // --- Buat baru ---
                if ($day === null) {
                    $new = new AttendanceDay([
                        ...$values,
                        'employee_id' => $item['employee_id'],
                        'date' => $item['date'],
                        'source' => $source,
                        'import_batch_id' => $batch?->id,
                        'updated_by' => $userId,
                    ]);
                    $new->id = (string) Str::uuid7();
                    $new->created_at = $now;
                    $new->updated_at = $now;
                    $newDays[] = $new->getAttributes();

                    $filled = array_keys(array_filter($values, fn (mixed $value) => $value !== null));
                    $this->queueChange($changes, $changeItems, $new->id, $item, AttendanceWriteOutcome::Created, $source, $reason, $batch, $userId, $now,
                        [], Arr::only($new->getAttributes(), $filled));
                    $outcomes[] = AttendanceWriteOutcome::Created;

                    continue;
                }

                // --- Ubah ---
                $day->fill($values);
                $dirty = array_keys($day->getDirty());
                if ($dirty === []) {
                    $outcomes[] = AttendanceWriteOutcome::Unchanged;

                    continue;
                }
                if ($source->priority() < $day->getOriginal('source')->priority()) {
                    $day->discardChanges();
                    $outcomes[] = AttendanceWriteOutcome::Conflict;

                    continue;
                }

                $old = Arr::only($day->getRawOriginal(), $dirty);
                $day->forceFill(['source' => $source, 'import_batch_id' => $batch?->id, 'updated_by' => $userId])->save();
                $this->queueChange($changes, $changeItems, $day->id, $item, AttendanceWriteOutcome::Updated, $source, $reason, $batch, $userId, $now,
                    $old, Arr::only($day->getAttributes(), $dirty));
                $outcomes[] = AttendanceWriteOutcome::Updated;
            }

            foreach (array_chunk($newDays, self::CHUNK) as $chunk) {
                AttendanceDay::query()->insert($chunk);
            }
            foreach (array_chunk($changes, self::CHUNK) as $chunk) {
                AttendanceDayChange::query()->insert($chunk);
            }
            foreach (array_chunk($changeItems, self::CHUNK) as $chunk) {
                AttendanceDayChangeItem::query()->insert($chunk);
            }
            foreach (array_chunk($deleteIds, self::CHUNK) as $chunk) {
                AttendanceDay::query()->whereKey($chunk)->delete();   // jejaknya tetap ada (attendance_day_id jadi NULL)
            }

            return $outcomes;
        });
    }

    /**
     * Hari yang sudah tersimpan untuk item-item itu, dikunci dengan "id karyawan|tanggal".
     *
     * @return array<string, AttendanceDay>
     */
    private function existingDays(array $items): array
    {
        $dates = array_column($items, 'date');
        $days = [];

        foreach (array_chunk(array_values(array_unique(array_column($items, 'employee_id'))), self::CHUNK) as $employeeIds) {
            AttendanceDay::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('date', '>=', min($dates))
                ->where('date', '<', Carbon::parse(max($dates))->addDay()->toDateString())
                ->lockForUpdate()
                ->get()
                ->each(function (AttendanceDay $day) use (&$days) {
                    $days[$day->employee_id.'|'.$day->date->toDateString()] = $day;
                });
        }

        return $days;
    }

    /**
     * Siapkan jejak: satu baris perubahan + satu baris detail per kolom (nilai lama -> baru, sebagai teks).
     *
     * @param  array<string, mixed>  $oldValues  [kolom => nilai mentah lama]
     * @param  array<string, mixed>  $newValues  [kolom => nilai mentah baru]
     */
    private function queueChange(
        array &$changes,
        array &$changeItems,
        string $dayId,
        array $item,
        AttendanceWriteOutcome $action,
        AttendanceSource $source,
        ?string $reason,
        ?AttendanceImportBatch $batch,
        ?string $userId,
        Carbon $now,
        array $oldValues,
        array $newValues,
    ): void {
        $changeId = (string) Str::uuid7();
        $changes[] = [
            'id' => $changeId,
            'attendance_day_id' => $dayId,
            'employee_id' => $item['employee_id'],
            'date' => $item['date'],
            'action' => $action->value,
            'source' => $source->value,
            'import_batch_id' => $batch?->id,
            'reason' => $reason,
            'changed_by' => $userId,
            'created_at' => $now,
        ];

        foreach (array_unique([...array_keys($oldValues), ...array_keys($newValues)]) as $field) {
            $changeItems[] = [
                'id' => (string) Str::uuid7(),
                'attendance_day_change_id' => $changeId,
                'field' => $field,
                'old_value' => $this->text($oldValues[$field] ?? null),
                'new_value' => $this->text($newValues[$field] ?? null),
            ];
        }
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }
}
