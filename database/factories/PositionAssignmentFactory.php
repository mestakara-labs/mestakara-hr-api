<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\SubUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Penugasan jabatan contoh untuk pengujian (bawaan: Definitif, aktif sejak 2020).
 *
 * @extends Factory<PositionAssignment>
 */
class PositionAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory()->active(),
            'position_id' => Position::factory(),
            'sub_unit_id' => SubUnit::factory(),
            'assignment_status' => 'Definitif',
            'start_date' => '2020-01-01',
            'end_date' => null,
        ];
    }

    /** Penugasan yang sudah selesai (riwayat). */
    public function finished(): static
    {
        return $this->state(fn () => ['start_date' => '2018-01-01', 'end_date' => '2019-12-31']);
    }

    /** Penugasan yang TMT mulainya belum tiba. */
    public function upcoming(): static
    {
        return $this->state(fn () => ['start_date' => now()->addMonth()->toDateString()]);
    }

    /** Penugasan di jabatan dengan level BRM tertentu. */
    public function atBrmLevel(int $brmLevel): static
    {
        return $this->state(fn () => [
            'position_id' => $brmLevel === 1
                ? Position::factory()->manager()
                : Position::factory()->state(['brm_level' => $brmLevel]),
        ]);
    }
}
