<?php

namespace Database\Factories;

use App\Models\PlacementCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Kategori objek contoh untuk pengujian.
 *
 * @extends Factory<PlacementCategory>
 */
class PlacementCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Kategori '.fake()->unique()->word(),
            'description' => null,
        ];
    }
}
