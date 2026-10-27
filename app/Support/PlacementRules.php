<?php

namespace App\Support;

use App\Enums\AssignmentStatus;
use App\Models\PlacementObject;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\SubUnit;

/**
 * ATURAN RENCANA PENEMPATAN (calon penugasan jabatan) — dipakai Mutasi dan Penambahan Karyawan.
 *
 *   - Jabatan, Status Jabatan, dan Sub Unit wajib & harus ada
 *   - Objek penempatan (opsional, boleh lebih dari satu) harus tersedia di sub unit yang dipilih
 *   - Atasan wajib mulai BRM-2: penugasan AKTIF karyawan lain dengan level BRM lebih tinggi
 *   - TMT Selesai (opsional) tidak boleh lebih awal dari TMT mulai
 *
 * Isian ($placement): position_id, assignment_status, sub_unit_id, placement_object_ids[],
 * supervisor_assignment_ids[], end_date (semua teks; "" = kosong).
 */
class PlacementRules
{
    /**
     * Hasilnya [nama isian => pesan]; kosong = benar.
     *
     * @param  string  $employeeId  karyawan yang ditempatkan (tidak bisa menjadi atasan dirinya sendiri)
     * @param  string  $startDate  TMT mulai penugasan ("" = belum diisi)
     * @param  string  $startLabel  nama TMT mulai di pesan, mis. "TMT Berlaku" atau "TMT Aktif"
     */
    public static function errors(array $placement, string $employeeId, string $startDate, string $startLabel): array
    {
        $errors = [];

        // Jabatan
        $position = $placement['position_id'] !== '' ? Position::find($placement['position_id']) : null;
        if ($placement['position_id'] === '') {
            $errors['position_id'] = 'Pilih Jabatan';
        } elseif ($position === null) {
            $errors['position_id'] = 'Jabatan tidak ditemukan';
        }

        // Status jabatan
        if ($placement['assignment_status'] === '') {
            $errors['assignment_status'] = 'Pilih Status Jabatan';
        } elseif (AssignmentStatus::tryFrom($placement['assignment_status']) === null) {
            $errors['assignment_status'] = 'Status Jabatan tidak dikenal';
        }

        // Sub unit & objek penempatan
        if ($placement['sub_unit_id'] === '') {
            $errors['sub_unit_id'] = 'Pilih Sub Unit';
        } elseif (! SubUnit::whereKey($placement['sub_unit_id'])->exists()) {
            $errors['sub_unit_id'] = 'Sub unit tidak ditemukan';
        }

        foreach ($placement['placement_object_ids'] as $objectId) {
            $object = PlacementObject::with('subUnits')->find($objectId);

            if ($object === null) {
                $errors['placement_object_ids'] = 'Objek penempatan tidak ditemukan';
            } elseif (! $object->subUnits->contains('id', $placement['sub_unit_id'])) {
                $errors['placement_object_ids'] = "Objek \"{$object->name}\" tidak tersedia di sub unit yang dipilih";
            }
        }

        // Atasan langsung
        if ($position !== null) {
            $supervisorError = self::supervisorError($placement['supervisor_assignment_ids'], $position, $employeeId);
            if ($supervisorError !== null) {
                $errors['supervisor_assignment_ids'] = $supervisorError;
            }
        }

        // TMT Selesai
        if ($placement['end_date'] !== '' && $startDate !== '' && $placement['end_date'] < $startDate) {
            $errors['end_date'] = "TMT Selesai tidak boleh lebih awal dari {$startLabel}";
        }

        return $errors;
    }

    /** Pesan kesalahan atasan langsung, atau null kalau benar. */
    private static function supervisorError(array $supervisorIds, Position $position, string $employeeId): ?string
    {
        if ($supervisorIds === []) {
            return Position::needsSupervisor($position->brm_level) ? 'Pilih minimal satu Atasan Langsung' : null;
        }

        $supervisors = PositionAssignment::with('position')->findMany($supervisorIds);
        $allValid = $supervisors->count() === count(array_unique($supervisorIds))
            && $supervisors->every(fn (PositionAssignment $supervisor) => $supervisor->isActive()
                && $supervisor->employee_id !== $employeeId
                && $supervisor->position->brm_level < $position->brm_level);

        return $allValid ? null : 'Atasan Langsung harus penugasan aktif karyawan lain dengan level BRM lebih tinggi';
    }
}
