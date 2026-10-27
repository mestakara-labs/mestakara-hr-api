<?php

namespace Database\Factories;

use App\Models\CostCenter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Cost center contoh untuk pengujian.
 *
 * @extends Factory<CostCenter>
 */
class CostCenterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('4A44TST###'),
            'name' => 'Biaya '.fake()->word(),
            'description' => null,
        ];
    }
}
