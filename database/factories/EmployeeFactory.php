<?php

namespace Database\Factories;

use App\Enums\ActivationType;
use App\Enums\DocumentScope;
use App\Enums\EmployeeActiveStatus;
use App\Enums\ProposalStatus;
use App\Models\Employee;
use App\Models\EmployeeActivation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Karyawan contoh untuk pengujian (bawaan: biodata lengkap, Belum Aktif, tanpa riwayat pendidikan).
 *
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employment_status' => 'Karyawan Tetap',
            'nik' => fake()->unique()->numerify('2023####'),
            'registration_number' => fake()->unique()->numerify('010#########'),
            'name' => fake()->name(),
            'ktp_number' => fake()->unique()->numerify('################'),
            'gender' => 'Laki-laki',
            'birth_place' => 'Bogor',
            'birth_date' => '1990-05-01',
            'hire_date' => '2015-01-02',
            'religion' => 'Islam',
            'marital_status' => 'Kawin',
            'phone' => '081234567890',
            'active_status' => EmployeeActiveStatus::Pending->value,
        ];
    }

    /**
     * Karyawan yang sudah Aktif, lengkap dengan riwayatnya: usulan Penambahan BERLAKU
     * (Penerimaan Baru) dengan TMT = Tanggal Masuk.
     */
    public function active(): static
    {
        return $this->state(fn () => ['active_status' => EmployeeActiveStatus::Active->value])
            ->afterCreating(function (Employee $employee) {
                $activation = EmployeeActivation::create([
                    'status' => ProposalStatus::Effective,
                    'document_scope' => DocumentScope::Collective,
                    'created_by' => User::factory()->create()->id,
                    'activated_at' => now(),
                ]);

                $activation->items()->create([
                    'employee_id' => $employee->id,
                    'type' => ActivationType::NewHire->value,
                    'effective_date' => $employee->hire_date ?? '2015-01-02',
                ]);
            });
    }
}
