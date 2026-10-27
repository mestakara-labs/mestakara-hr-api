<?php

namespace Database\Factories;

use App\Models\PlacementCategory;
use App\Models\PlacementObject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Objek penempatan contoh untuk pengujian. Sub unitnya dipasang lewat hasAttached / attach.
 *
 * @extends Factory<PlacementObject>
 */
class PlacementObjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => PlacementCategory::factory(),
            'name' => 'Objek '.fake()->unique()->words(2, true),
            'description' => null,
        ];
    }
}
