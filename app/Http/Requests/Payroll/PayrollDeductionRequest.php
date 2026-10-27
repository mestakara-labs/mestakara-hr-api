<?php

namespace App\Http\Requests\Payroll;

use App\Http\Requests\Attendance\AttendancePeriodRequest;

/**
 * Ubah potongan satu / banyak karyawan:
 *
 *   { period: "2026-10", employee_ids: ["..."], bpjs?: 50000, pph21?: 0, unit?: 25000 }
 *
 * Hanya isian yang dikirim yang diubah (minimal satu); angka bulat ≥ 0.
 */
class PayrollDeductionRequest extends AttendancePeriodRequest
{
    public function rules(): array
    {
        $amount = ['integer', 'min:0', 'max:4294967295'];

        return [
            ...parent::rules(),
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['required', 'uuid', 'distinct'],
            /** Potongan BPJS TK/KES. */
            'bpjs' => ['required_without_all:pph21,unit', ...$amount],
            /** Potongan PPIP / PPh Ps.21. */
            'pph21' => ['required_without_all:bpjs,unit', ...$amount],
            /** Potongan Kebun / Unit. */
            'unit' => ['required_without_all:bpjs,pph21', ...$amount],
        ];
    }

    public function messages(): array
    {
        $amountMessage = 'harus berupa angka bulat minimal 0';

        return [
            ...parent::messages(),
            'employee_ids.required' => 'Pilih minimal satu karyawan',
            'employee_ids.min' => 'Pilih minimal satu karyawan',
            'employee_ids.*.uuid' => 'Karyawan tidak ditemukan',
            'employee_ids.*.distinct' => 'Karyawan dipilih lebih dari sekali',
            'bpjs.required_without_all' => 'Isi minimal satu potongan (BPJS, PPh 21, atau Kebun/Unit)',
            'pph21.required_without_all' => 'Isi minimal satu potongan (BPJS, PPh 21, atau Kebun/Unit)',
            'unit.required_without_all' => 'Isi minimal satu potongan (BPJS, PPh 21, atau Kebun/Unit)',
            'bpjs.*' => "Potongan BPJS {$amountMessage}",
            'pph21.*' => "Potongan PPh 21 {$amountMessage}",
            'unit.*' => "Potongan Kebun/Unit {$amountMessage}",
        ];
    }

    /** @return array<string, int> isian potongan yang dikirim saja */
    public function deductionValues(): array
    {
        return array_map('intval', array_intersect_key($this->validated(), array_flip(['bpjs', 'pph21', 'unit'])));
    }
}
