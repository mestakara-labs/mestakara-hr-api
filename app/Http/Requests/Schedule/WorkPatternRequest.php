<?php

namespace App\Http\Requests\Schedule;

use App\Models\PatternSlot;
use App\Models\Shift;
use App\Models\WorkGroupMember;
use App\Models\WorkPattern;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Isian form Pola Kerja (tambah & ubah), dikirim sebagai JSON:
 *
 *   { name, cycle_days, description,
 *     slots: [{ id, code, name, rotates, shift_ids: [id shift | null (libur), ...] }, ...] }
 *
 * Urutan slots = urutan putaran. Slot baru memakai id sementara "baru-...".
 * Error per slot memakai nama isian "slots.<urutan>.<isian>", mis. "slots.1.code" = kode slot kedua.
 */
class WorkPatternRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /**
             * Nama pola, unik.
             *
             * @example Tiga Shift + Tengah
             */
            'name' => ['required', 'string', 'max:150', Rule::unique('work_patterns')->ignore($this->route('work_pattern')?->id)],
            /**
             * Panjang siklus 1–28 hari.
             *
             * @example 7
             */
            'cycle_days' => ['required', 'integer', 'between:'.WorkPattern::MIN_CYCLE_DAYS.','.WorkPattern::MAX_CYCLE_DAYS],
            'description' => ['nullable', 'string'],
            /** Slot pola (minimal satu). */
            'slots' => ['present', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama Pola wajib diisi',
            'name.unique' => 'Pola "'.$this->input('name').'" sudah ada',
            'cycle_days.*' => 'Panjang Siklus harus '.WorkPattern::MIN_CYCLE_DAYS.'–'.WorkPattern::MAX_CYCLE_DAYS.' hari',
        ];
    }

    /** Pemeriksaan setiap slot. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $errors = $validator->errors();
                $slots = array_values(array_filter((array) $this->input('slots', []), 'is_array'));
                $cycleDays = (int) $this->input('cycle_days');

                if ($slots === []) {
                    $errors->add('slots', 'Tambahkan minimal satu slot');

                    return;
                }

                $codes = array_map(fn (array $slot) => mb_strtoupper(trim((string) ($slot['code'] ?? ''))), $slots);
                $knownShiftIds = Shift::pluck('id')->all();

                foreach ($slots as $index => $slot) {
                    $code = $codes[$index];
                    $shiftIds = array_map(fn ($id) => $id ?: null, (array) ($slot['shift_ids'] ?? []));

                    if ($code === '') {
                        $errors->add("slots.{$index}.code", 'Kode slot wajib diisi');
                    } elseif (mb_strlen($code) > 3) {
                        $errors->add("slots.{$index}.code", 'Kode slot maksimal 3 karakter');
                    } elseif (array_search($code, $codes, true) !== $index) {
                        $errors->add("slots.{$index}.code", "Kode \"{$code}\" dipakai lebih dari sekali");
                    }

                    if (count($shiftIds) !== $cycleDays) {
                        $errors->add("slots.{$index}.shift_ids", 'Isi shift harus sepanjang siklus');
                    } elseif (array_diff(array_filter($shiftIds), $knownShiftIds) !== []) {
                        $errors->add("slots.{$index}.shift_ids", 'Ada shift yang tidak ditemukan');
                    } elseif (array_filter($shiftIds) === []) {
                        $errors->add("slots.{$index}.shift_ids", 'Slot ini libur setiap hari; isi minimal satu hari kerja');
                    }
                }

                $this->checkRemovedSlots($validator, array_column($slots, 'id'));
            },
        ];
    }

    /** Slot yang DIHAPUS tidak boleh masih menjadi slot awal anggota regu (riwayat anggota merujuk ke slot itu). */
    private function checkRemovedSlots(Validator $validator, array $sentIds): void
    {
        /** @var WorkPattern|null $pattern */
        $pattern = $this->route('work_pattern');
        if ($pattern === null) {
            return;
        }

        $usedCodes = PatternSlot::query()
            ->where('work_pattern_id', $pattern->id)
            ->whereNotIn('id', $sentIds)
            ->whereIn('id', WorkGroupMember::select('start_slot_id'))
            ->pluck('code');

        if ($usedCodes->isNotEmpty()) {
            $validator->errors()->add('slots', "Slot {$usedCodes->join(', ')} masih dipakai anggota regu, jadi tidak bisa dihapus");
        }
    }

    /**
     * Slot yang sudah dirapikan: [[id, code (huruf besar), name, rotates, shift_ids], ...].
     */
    public function slots(): array
    {
        $slots = [];

        foreach (array_values(array_filter((array) $this->input('slots', []), 'is_array')) as $slot) {
            $code = mb_strtoupper(trim((string) ($slot['code'] ?? '')));

            $slots[] = [
                'id' => (string) ($slot['id'] ?? ''),
                'code' => $code,
                'name' => trim((string) ($slot['name'] ?? '')) ?: "Posisi {$code}",
                'rotates' => filter_var($slot['rotates'] ?? true, FILTER_VALIDATE_BOOL),
                'shift_ids' => array_map(fn ($id) => $id ?: null, (array) ($slot['shift_ids'] ?? [])),
            ];
        }

        return $slots;
    }
}
