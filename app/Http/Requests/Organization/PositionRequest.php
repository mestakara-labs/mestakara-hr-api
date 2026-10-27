<?php

namespace App\Http\Requests\Organization;

use App\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Isian form Jabatan (tambah & ubah): { code, title, brm_level, job_grade, division_id, description }.
 *
 *   BRM-1           -> division_id boleh kosong ("")
 *   BRM-2 s.d. 6    -> division_id wajib
 */
class PositionRequest extends FormRequest
{
    public function rules(): array
    {
        $currentId = $this->route('position')?->id;
        $brmLevel = (int) $this->input('brm_level');

        return [
            /**
             * Kode jabatan, unik.
             *
             * @example JAB-015
             */
            'code' => ['required', 'string', 'max:20', Rule::unique('positions')->ignore($currentId)],
            /**
             * Nama jabatan.
             *
             * @example Asisten Personalia
             */
            'title' => ['required', 'string', 'max:150'],
            /**
             * Level BRM: 1 (Manager) sampai 6 (Pelaksana).
             *
             * @example 3
             */
            'brm_level' => ['required', 'integer', Rule::in(Position::BRM_LEVELS)],
            /**
             * Job Grade 1–99 (opsional, null = kosong).
             *
             * @example 12
             */
            'job_grade' => ['nullable', 'integer', 'between:'.Position::JOB_GRADE_MIN.','.Position::JOB_GRADE_MAX],
            /** Id bidang. Kosongkan ("") untuk jabatan BRM-1. */
            'division_id' => [
                Rule::requiredIf(Position::needsDivision($brmLevel)),
                'nullable',
                'string',
                'exists:divisions,id',
            ],
            'description' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        $jobGradeMessage = 'Job Grade harus angka bulat '.Position::JOB_GRADE_MIN.'–'.Position::JOB_GRADE_MAX;

        return [
            'code.required' => 'Kode wajib diisi',
            'code.unique' => 'Kode "'.$this->input('code').'" sudah dipakai jabatan lain',
            'title.required' => 'Nama Jabatan wajib diisi',
            'brm_level.required' => 'Pilih Level BRM',
            'brm_level.integer' => 'Pilih Level BRM',
            'brm_level.in' => 'Pilih Level BRM',
            'job_grade.integer' => $jobGradeMessage,
            'job_grade.between' => $jobGradeMessage,
            'division_id.required' => 'Pilih Bidang',
            'division_id.exists' => 'Bidang tidak ditemukan',
        ];
    }
}
