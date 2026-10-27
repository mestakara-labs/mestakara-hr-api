<?php

namespace Database\Factories;

use App\Models\Division;
use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Jabatan contoh untuk pengujian (bawaan: BRM-3 dengan bidang).
 *
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'JAB-'.fake()->unique()->numerify('###'),
            'title' => 'Asisten '.fake()->words(2, true),
            'division_id' => Division::factory(),
            'brm_level' => 3,
            'job_grade' => null,
            'description' => null,
        ];
    }

    /** Jabatan Manager (BRM-1) tanpa bidang. */
    public function manager(): static
    {
        return $this->state(fn () => [
            'title' => 'Manager',
            'division_id' => null,
            'brm_level' => 1,
        ]);
    }
}
