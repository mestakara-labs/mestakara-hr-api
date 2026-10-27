<?php

namespace App\Services\Attendance;

use App\Enums\OvertimeChangeAction;
use App\Enums\OvertimeSource;
use App\Models\OvertimeEntry;
use App\Models\OvertimeEntryChange;
use BackedEnum;

/**
 * Mencatat JEJAK PERUBAHAN LEMBUR (overtime_entry_changes + detail per isian). Dipanggil SEBELUM baris dihapus,
 * supaya jejaknya masih menunjuk ke baris itu (sesudah dihapus, overtime_entry_id jadi NULL).
 *
 *   OvertimeTrail::record($entry, OvertimeChangeAction::Updated, OvertimeSource::Manual, 'Salah jam', $lama, $baru);
 */
class OvertimeTrail
{
    /** Isian lembur yang dicatat di jejak. */
    public const FIELDS = ['date', 'start_time', 'end_time', 'duration_minutes', 'description', 'source'];

    /**
     * @param  array<string, mixed>  $oldValues  [isian => nilai lama] (kosong untuk baris baru)
     * @param  array<string, mixed>  $newValues  [isian => nilai baru] (kosong untuk baris dihapus)
     */
    public static function record(
        OvertimeEntry $entry,
        OvertimeChangeAction $action,
        OvertimeSource $source,
        ?string $reason,
        array $oldValues,
        array $newValues,
    ): void {
        $change = OvertimeEntryChange::create([
            'overtime_entry_id' => $entry->id,
            'employee_id' => $entry->employee_id,
            'date' => $entry->date->toDateString(),
            'overtime_type' => $entry->overtime_type,
            'action' => $action,
            'source' => $source,
            'reason' => $reason,
            'changed_by' => auth()->id(),
        ]);

        foreach (self::FIELDS as $field) {
            $old = self::text($oldValues[$field] ?? null);
            $new = self::text($newValues[$field] ?? null);
            if ($old !== $new) {
                $change->items()->create(['field' => $field, 'old_value' => $old, 'new_value' => $new]);
            }
        }
    }

    /** Nilai isian lembur dalam bentuk sederhana untuk jejak: tanggal "YYYY-MM-DD", jam "HH:MM". */
    public static function values(OvertimeEntry $entry): array
    {
        return [
            'date' => $entry->date->toDateString(),
            'start_time' => substr($entry->start_time, 0, 5),
            'end_time' => substr($entry->end_time, 0, 5),
            'duration_minutes' => $entry->duration_minutes,
            'description' => $entry->description,
            'source' => $entry->source,
        ];
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }
}
