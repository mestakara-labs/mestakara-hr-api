<?php

namespace App\Http\Requests\Assignment;

use App\Models\Position;
use App\Models\PositionAssignment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Isian "Ubah Atasan" (tanpa SK): { assignment_ids[], supervisor_assignment_ids[], notes }.
 *
 * Aturan (sama dengan front end, lib/assignment-rules.ts):
 *   - penugasan yang diubah: minimal satu, semuanya ada dan masih AKTIF;
 *   - atasan baru wajib kalau salah satu jabatannya BRM-2 ke bawah;
 *   - setiap atasan harus penugasan AKTIF milik karyawan lain, dengan level BRM lebih tinggi
 *     (angka lebih kecil) dari SEMUA karyawan yang dipilih.
 */
class ChangeSupervisorsRequest extends FormRequest
{
    /** Penugasan yang atasannya diubah, beserta jabatannya. */
    public ?Collection $targets = null;

    public function rules(): array
    {
        return [
            /** Id penugasan yang atasannya diubah (minimal satu). */
            'assignment_ids' => ['present', 'array'],
            'assignment_ids.*' => ['string'],
            /** Id PENUGASAN atasan baru. Kosong = tanpa atasan (hanya untuk BRM-1). */
            'supervisor_assignment_ids' => ['present', 'array'],
            'supervisor_assignment_ids.*' => ['string'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $error = $this->findError();
                if ($error !== null) {
                    $validator->errors()->add(...$error);
                }
            },
        ];
    }

    /** Kesalahan pertama yang ditemukan: [isian, pesan], atau null kalau semua benar. */
    private function findError(): ?array
    {
        $targetIds = array_unique($this->input('assignment_ids'));
        $supervisorIds = array_unique($this->input('supervisor_assignment_ids'));

        if ($targetIds === []) {
            return ['assignment_ids', 'Pilih minimal satu karyawan'];
        }

        $this->targets = PositionAssignment::with('position')->findMany($targetIds);

        if ($this->targets->count() !== count($targetIds)) {
            return ['assignment_ids', 'Penugasan jabatan tidak ditemukan'];
        }
        if ($this->targets->contains(fn (PositionAssignment $target) => ! $target->isActive())) {
            return ['assignment_ids', 'Atasan hanya bisa diubah untuk penugasan yang masih aktif'];
        }

        if ($supervisorIds === []) {
            $needsSupervisor = $this->targets->contains(fn (PositionAssignment $target) => Position::needsSupervisor($target->position->brm_level));

            return $needsSupervisor ? ['supervisor_assignment_ids', 'Pilih minimal satu Atasan Langsung'] : null;
        }

        $supervisors = PositionAssignment::with('position')->findMany($supervisorIds);
        $allValid = $supervisors->count() === count($supervisorIds)
            && $supervisors->every(fn (PositionAssignment $supervisor) => $this->targets->every(
                fn (PositionAssignment $target) => $this->canSupervise($supervisor, $target),
            ));

        if (! $allValid) {
            return ['supervisor_assignment_ids', 'Atasan Langsung harus penugasan aktif karyawan lain dengan level BRM lebih tinggi dari semua karyawan yang dipilih'];
        }

        return null;
    }

    /** Boleh `$supervisor` menjadi atasan `$target`? */
    private function canSupervise(PositionAssignment $supervisor, PositionAssignment $target): bool
    {
        return $supervisor->isActive()
            && $supervisor->id !== $target->id
            && $supervisor->employee_id !== $target->employee_id
            && $supervisor->position->brm_level < $target->position->brm_level;
    }
}
