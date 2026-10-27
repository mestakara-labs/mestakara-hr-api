<?php

namespace Database\Factories;

use App\Models\SubUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sub unit contoh untuk pengujian.
 *
 * @extends Factory<SubUnit>
 */
class SubUnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Agrowisata '.fake()->unique()->city(),
            'afdeling_code' => null,
            'description' => null,
        ];
    }
}
