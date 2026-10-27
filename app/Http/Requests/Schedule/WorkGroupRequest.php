<?php

namespace App\Http\Requests\Schedule;

use App\Enums\EmployeeActiveStatus;
use App\Enums\RotationMode;
use App\Models\Employee;
use App\Models\PlacementObject;
use App\Models\SubUnit;
use App\Models\WorkGroup;
use App\Models\WorkGroupMember;
use App\Models\WorkPattern;
use App\Services\Schedule\Rotation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Isian Regu Kerja (tambah & ubah), dikirim sebagai JSON. Regu dan anggota BARUNYA disimpan sekaligus:
 *
 *   { name, sub_unit_id, placement_object_id, work_pattern_id, leader_employee_id,
 *     rotation_mode, rotate_every_days, anchor_date, description,
 *     members: [{ employee_id, start_slot_id }, ...],  members_start_date }
 *
 * Anggota lama tidak diubah di sini (diakhiri lewat PUT /work-groups/{id}/members/{memberId}).
 * Error anggota baru memakai nama isian "members.<urutan>.<isian>".
 */
class WorkGroupRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /**
             * Nama regu, unik.
             *
             * @example House Keeping Gunung Mas
             */
            'name' => ['required', 'string', 'max:150'],
            'sub_unit_id' => ['nullable', 'string'],
            'placement_object_id' => ['nullable', 'string'],
            'work_pattern_id' => ['nullable', 'string'],
            'leader_employee_id' => ['nullable', 'string'],
            /** Tetap / Bergilir. */
            'rotation_mode' => ['nullable', 'string'],
            /** Bergilir: maju satu slot setiap sekian hari (1–56). */
            'rotate_every_days' => ['nullable', 'integer'],
            /**
             * Tanggal Mulai Berlaku Jadwal = hari ke-1 pola (hari apa pun).
             *
             * @example 2026-09-01
             */
            'anchor_date' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            /** Anggota baru (boleh kosong). */
            'members' => ['present', 'array'],
            /** Tanggal bergabung anggota baru. */
            'members_start_date' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return ['name.required' => 'Nama Regu wajib diisi'];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ([...$this->groupErrors(), ...$this->newMemberErrors()] as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }

    /** Regu yang sedang diubah (null saat tambah baru). */
    public function currentGroup(): ?WorkGroup
    {
        return $this->route('work_group');
    }

    /** Isian regu yang disimpan (rotasi tiap N hari hanya dipakai mode Bergilir; bawaan 7). */
    public function groupData(): array
    {
        $isRotating = $this->input('rotation_mode') === RotationMode::Rotating->value;

        return [
            'name' => trim((string) $this->input('name')),
            'sub_unit_id' => $this->input('sub_unit_id'),
            'placement_object_id' => $this->input('placement_object_id') ?: null,
            'work_pattern_id' => $this->input('work_pattern_id'),
            'leader_employee_id' => $this->input('leader_employee_id') ?: null,
            'rotation_mode' => $this->input('rotation_mode'),
            'rotate_every_days' => $isRotating ? (int) $this->input('rotate_every_days') : 7,
            'anchor_date' => $this->input('anchor_date'),
            'description' => $this->input('description') ?: null,
        ];
    }

    /** Pemeriksaan isian regu. */
    private function groupErrors(): array
    {
        $errors = [];
        $current = $this->currentGroup();
        $name = trim((string) $this->input('name'));

        $isNameUsed = WorkGroup::query()->where('name', $name)->when($current, fn ($query) => $query->whereKeyNot($current->id))->exists();
        if ($name !== '' && $isNameUsed) {
            $errors['name'] = "Regu \"{$name}\" sudah ada";
        }

        $subUnitId = (string) $this->input('sub_unit_id');
        if (! SubUnit::whereKey($subUnitId)->exists()) {
            $errors['sub_unit_id'] = 'Pilih Sub Unit';
        }

        if ($this->filled('placement_object_id')) {
            $object = PlacementObject::with('subUnits')->find($this->input('placement_object_id'));
            if ($object === null) {
                $errors['placement_object_id'] = 'Objek penempatan tidak ditemukan';
            } elseif (! $object->subUnits->contains('id', $subUnitId)) {
                $errors['placement_object_id'] = "Objek \"{$object->name}\" tidak tersedia di sub unit yang dipilih";
            }
        }

        $patternId = (string) $this->input('work_pattern_id');
        if (! WorkPattern::whereKey($patternId)->exists()) {
            $errors['work_pattern_id'] = 'Pilih Pola Kerja';
        } elseif ($current && $current->work_pattern_id !== $patternId) {
            $activeCount = $current->members()
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', Carbon::today()))
                ->count();
            if ($activeCount > 0) {
                $errors['work_pattern_id'] = "Pola tidak bisa diganti selama masih ada {$activeCount} anggota aktif. Akhiri keanggotaan mereka dulu, lalu tambahkan lagi dengan slot pola baru.";
            }
        }

        if ($this->filled('leader_employee_id')) {
            $leader = Employee::find($this->input('leader_employee_id'));
            if ($leader === null) {
                $errors['leader_employee_id'] = 'Ketua regu tidak ditemukan';
            } elseif ($leader->active_status !== EmployeeActiveStatus::Active->value) {
                $errors['leader_employee_id'] = 'Ketua regu harus karyawan Aktif';
            }
        }

        $mode = RotationMode::tryFrom((string) $this->input('rotation_mode'));
        if ($mode === null) {
            $errors['rotation_mode'] = 'Pilih Mode Rotasi';
        } elseif ($mode === RotationMode::Rotating) {
            $every = filter_var($this->input('rotate_every_days'), FILTER_VALIDATE_INT);
            if ($every === false || $every < 1 || $every > RotationMode::MAX_ROTATE_EVERY_DAYS) {
                $errors['rotate_every_days'] = 'Rotasi harus tiap 1–'.RotationMode::MAX_ROTATE_EVERY_DAYS.' hari';
            }
        }

        // Hari apa pun boleh: tanggal ini menjadi hari ke-1 pola.
        $anchorDate = (string) $this->input('anchor_date');
        if (! $this->isDate($anchorDate)) {
            $errors['anchor_date'] = 'Pilih Tanggal Mulai Berlaku Jadwal';
        } elseif ($current) {
            $firstStart = $current->members()->min('start_date');
            if ($firstStart && $anchorDate > Carbon::parse($firstStart)->toDateString()) {
                $errors['anchor_date'] = 'Tanggal Mulai Berlaku Jadwal tidak boleh setelah anggota pertama bergabung ('.Carbon::parse($firstStart)->toDateString().')';
            }
        }

        return $errors;
    }

    /**
     * Pemeriksaan anggota BARU:
     *   - tanggal bergabung ≥ Tanggal Mulai Berlaku Jadwal
     *   - karyawan Aktif, tidak dipilih dua kali, dan tidak sedang aktif di regu mana pun
     *   - slot milik pola regu; slot bergilir di regu Bergilir hanya boleh dipegang satu orang
     *     (dibandingkan dengan anggota lama DAN anggota baru lain)
     */
    private function newMemberErrors(): array
    {
        $members = array_values(array_filter((array) $this->input('members', []), 'is_array'));
        if ($members === []) {
            return [];
        }

        $startDate = (string) $this->input('members_start_date');
        if (! $this->isDate($startDate)) {
            return ['members_start_date' => 'Pilih Tanggal Bergabung'];
        }

        $errors = [];
        $anchorDate = (string) $this->input('anchor_date');
        if ($this->isDate($anchorDate) && $startDate < $anchorDate) {
            $errors['members_start_date'] = 'Tanggal Bergabung tidak boleh sebelum Tanggal Mulai Berlaku Jadwal';
        }

        $pattern = WorkPattern::with('slots.days')->find($this->input('work_pattern_id'));
        $rotation = $this->isDate($anchorDate) && RotationMode::tryFrom((string) $this->input('rotation_mode'))
            ? new WorkGroup($this->groupData())
            : null;
        $oldMembers = $this->currentGroup()?->members()->with('employee')->get() ?? collect();
        /** Slot bergilir yang sudah diambil anggota baru sebelumnya di daftar ini: id slot => nama. */
        $takenByNew = [];
        $employeeIds = array_column($members, 'employee_id');

        foreach ($members as $index => $member) {
            $key = fn (string $field) => "members.{$index}.{$field}";
            $employee = Employee::find($member['employee_id'] ?? null);

            if ($employee === null) {
                $errors[$key('employee_id')] = 'Karyawan tidak ditemukan';

                continue;
            }
            if (array_search($employee->id, $employeeIds, true) !== $index) {
                $errors[$key('employee_id')] = "{$employee->name} dipilih lebih dari sekali";

                continue;
            }
            if ($employee->active_status !== EmployeeActiveStatus::Active->value) {
                $errors[$key('employee_id')] = "{$employee->name} bukan karyawan Aktif";

                continue;
            }

            $overlap = WorkGroupMember::query()
                ->with('workGroup')
                ->where('employee_id', $employee->id)
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $startDate))
                ->first();
            if ($overlap !== null) {
                $errors[$key('employee_id')] = "{$employee->name} masih aktif di regu \"{$overlap->workGroup->name}\". Akhiri keanggotaannya dulu.";
            }

            if ($pattern === null || $rotation === null) {
                continue;
            }

            $slot = $pattern->slots->firstWhere('id', $member['start_slot_id'] ?? null);
            if ($slot === null) {
                $errors[$key('start_slot_id')] = "Pilih Slot untuk {$employee->name}";

                continue;
            }
            if (! Rotation::isSingleHolderSlot($rotation, $slot)) {
                continue;
            }

            $oldHolder = $oldMembers->first(
                fn (WorkGroupMember $old) => Rotation::slotOn($rotation, $pattern->slots, $old, $startDate)?->id === $slot->id,
            );
            $holderName = $takenByNew[$slot->id] ?? $oldHolder?->employee->name;

            if ($holderName !== null) {
                $errors[$key('start_slot_id')] = "Slot {$slot->code} sudah dipegang {$holderName}. Slot bergilir hanya boleh satu orang.";
            } else {
                $takenByNew[$slot->id] = $employee->name;
            }
        }

        return $errors;
    }

    private function isDate(string $value): bool
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
