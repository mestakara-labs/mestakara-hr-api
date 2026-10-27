<?php

namespace Database\Factories;

use App\Models\PayrollGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Kelompok Daftar Upah contoh untuk pengujian.
 *
 * @extends Factory<PayrollGroup>
 */
class PayrollGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Kelompok '.fake()->unique()->word(),
            'sort_order' => 1,
            'description' => null,
        ];
    }
}
