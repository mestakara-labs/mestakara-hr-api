<?php

namespace Tests\Feature\Payroll;

use App\Models\OvertimeSetting;
use App\Models\User;
use Database\Seeders\OvertimeSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian Pengaturan Lembur (GET/PUT /overtime-settings). Penanda tangan diuji di SignatureSettingTest.
 */
class OvertimeSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_login(): void
    {
        $this->getJson('/api/v1/overtime-settings')->assertUnauthorized();
        $this->getJson('/api/v1/wage-schemes')->assertUnauthorized();
    }

    public function test_seeder_creates_defaults_with_empty_dw_description(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->seed(OvertimeSettingSeeder::class);
        $this->seed(OvertimeSettingSeeder::class);   // tidak membuat pengaturan kedua

        $this->assertSame(1, OvertimeSetting::count());

        $this->getJson('/api/v1/overtime-settings')
            ->assertOk()
            ->assertJsonPath('data.hourly_divisor', 173)
            ->assertJsonPath('data.first_hour_multiplier', 1.5)
            ->assertJsonPath('data.next_hour_multiplier', 2)
            ->assertJsonPath('data.max_hours_per_day', 4)
            ->assertJsonPath('data.max_hours_per_week', 18)
            ->assertJsonPath('data.rounding', 100)
            ->assertJsonPath('data.dw_default_start_time', '17:00')
            ->assertJsonPath('data.dw_default_description', null)
            ->assertJsonPath('data.time_step_minutes', 30)
            ->assertJsonMissingPath('data.spkl_signers')
            ->assertJsonMissingPath('data.payroll_signers');
    }

    public function test_update_saves_settings(): void
    {
        $user = User::factory()->create(['name' => 'Admin SDM']);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/overtime-settings', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.first_hour_multiplier', 1.5)
            ->assertJsonPath('data.dw_default_start_time', '16:30')
            ->assertJsonPath('data.dw_default_description', 'Membantu persiapan acara')
            ->assertJsonPath('data.updated_by', 'Admin SDM');

        $this->assertSame(1, OvertimeSetting::count());
    }

    public function test_update_validates_numbers_time_and_description(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $payload = [
            ...$this->payload(),
            'hourly_divisor' => 0,
            'first_hour_multiplier' => 0.5,
            'dw_default_start_time' => '5 sore',
            'dw_default_description' => '',
        ];

        $this->putJson('/api/v1/overtime-settings', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.hourly_divisor.0', 'Pembagi Upah Sejam harus berupa angka bulat lebih dari 0')
            ->assertJsonPath('errors.first_hour_multiplier.0', 'Pengali Jam Pertama harus berupa angka minimal 1')
            ->assertJsonPath('errors.dw_default_start_time.0', 'Jam Mulai Lembur DW harus berformat HH:MM, mis. 17:00')
            ->assertJsonPath('errors.dw_default_description.0', 'Uraian Pekerjaan Lembur DW wajib diisi');
    }

    private function payload(): array
    {
        return [
            'hourly_divisor' => 173,
            'first_hour_multiplier' => 1.5,
            'next_hour_multiplier' => 2,
            'max_hours_per_day' => 4,
            'max_hours_per_week' => 18,
            'rounding' => 100,
            'dw_default_start_time' => '16:30',
            'dw_default_description' => 'Membantu persiapan acara',
            'time_step_minutes' => 30,
        ];
    }
}
