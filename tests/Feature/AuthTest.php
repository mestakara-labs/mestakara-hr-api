<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pengujian login, data user login, dan logout (AuthController).
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_nik_returns_token_and_user(): void
    {
        $user = User::factory()->create(['nik' => '10928374', 'password' => 'Rahasia#1']);

        $response = $this->postJson('/api/v1/login', ['login' => '10928374', 'password' => 'Rahasia#1']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'nik', 'name', 'email', 'position', 'unit_name']]]);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_with_email_ignores_letter_case(): void
    {
        User::factory()->create(['email' => 'admin.sdm@agrowisata.test', 'password' => 'Rahasia#1']);

        $this->postJson('/api/v1/login', ['login' => 'Admin.SDM@agrowisata.test', 'password' => 'Rahasia#1'])
            ->assertOk();
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        User::factory()->create(['nik' => '10928374', 'password' => 'Rahasia#1']);

        $this->postJson('/api/v1/login', ['login' => '10928374', 'password' => 'salah'])
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'NIK/Email atau kata sandi salah.');
    }

    public function test_inactive_account_cannot_login(): void
    {
        User::factory()->inactive()->create(['nik' => '10928374', 'password' => 'Rahasia#1']);

        $this->postJson('/api/v1/login', ['login' => '10928374', 'password' => 'Rahasia#1'])
            ->assertForbidden();
    }

    public function test_login_without_fields_returns_validation_errors(): void
    {
        $this->postJson('/api/v1/login', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.login.0', 'NIK/Email wajib diisi.')
            ->assertJsonPath('errors.password.0', 'kata sandi wajib diisi.');
    }

    public function test_me_returns_logged_in_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('dashboard')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.nik', $user->nik)
            ->assertJsonPath('data.unit_name', 'Unit Agrowisata');
    }

    public function test_me_without_token_returns_401(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null);
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('dashboard')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
