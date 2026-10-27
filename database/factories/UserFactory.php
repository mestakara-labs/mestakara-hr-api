<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Pembuat akun contoh untuk pengujian (tests).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** Kata sandi akun contoh di-hash sekali saja supaya pengujian cepat. */
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'nik' => fake()->unique()->numerify('########'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::AdminSdm,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /** Akun yang sudah dinonaktifkan (tidak bisa login). */
    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
            'deactivated_at' => now(),
        ]);
    }
}
