<?php

namespace Tests\Feature\Organization;

use App\Models\Division;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian master Bidang (/divisions).
 */
class DivisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_list_returns_divisions_with_position_count(): void
    {
        $division = Division::factory()->create();
        Position::factory()->count(2)->for($division)->create();

        $this->getJson('/api/v1/divisions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $division->id)
            ->assertJsonPath('data.0.position_count', 2);
    }

    public function test_create_division(): void
    {
        $this->postJson('/api/v1/divisions', ['code' => 'ADK', 'name' => 'Administrasi & Keuangan', 'description' => ''])
            ->assertCreated()
            ->assertJsonPath('data.code', 'ADK')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.position_count', 0);

        $this->assertDatabaseHas('divisions', ['code' => 'ADK']);
    }

    public function test_create_division_rejects_duplicate_code(): void
    {
        Division::factory()->create(['code' => 'ADK']);

        $this->postJson('/api/v1/divisions', ['code' => 'ADK', 'name' => 'Lain', 'description' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Kode "ADK" sudah dipakai bidang lain');
    }

    public function test_update_division_keeps_its_own_code(): void
    {
        $division = Division::factory()->create(['code' => 'ADK']);

        $this->putJson("/api/v1/divisions/{$division->id}", ['code' => 'ADK', 'name' => 'Nama Baru', 'description' => 'Ket'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nama Baru');
    }

    public function test_delete_division_used_by_position_is_rejected(): void
    {
        $division = Division::factory()->create();
        Position::factory()->for($division)->create();

        $this->deleteJson("/api/v1/divisions/{$division->id}")->assertConflict();
        $this->assertModelExists($division);
    }

    public function test_delete_unused_division(): void
    {
        $division = Division::factory()->create();

        $this->deleteJson("/api/v1/divisions/{$division->id}")->assertOk();
        $this->assertModelMissing($division);
    }

    public function test_unknown_id_returns_404(): void
    {
        $this->getJson('/api/v1/divisions/tidak-ada')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }
}
