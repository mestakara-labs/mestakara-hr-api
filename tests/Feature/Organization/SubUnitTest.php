<?php

namespace Tests\Feature\Organization;

use App\Models\PlacementObject;
use App\Models\SubUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian master Sub Unit (/sub-units).
 */
class SubUnitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_create_sub_unit_stores_afdeling_code_in_upper_case(): void
    {
        $this->postJson('/api/v1/sub-units', ['name' => 'Agrowisata Gunung Mas', 'afdeling_code' => ' afd03 ', 'description' => ''])
            ->assertCreated()
            ->assertJsonPath('data.afdeling_code', 'AFD03')
            ->assertJsonPath('data.object_count', 0);
    }

    public function test_empty_afdeling_code_becomes_null(): void
    {
        $this->postJson('/api/v1/sub-units', ['name' => 'Kantor Induk', 'afdeling_code' => '', 'description' => ''])
            ->assertCreated()
            ->assertJsonPath('data.afdeling_code', null);
    }

    public function test_afdeling_code_must_be_unique_and_without_spaces(): void
    {
        SubUnit::factory()->create(['afdeling_code' => 'AFD03']);

        $this->postJson('/api/v1/sub-units', ['name' => 'A', 'afdeling_code' => 'afd03', 'description' => ''])
            ->assertJsonPath('errors.afdeling_code.0', 'Kode Afdeling "AFD03" sudah dipakai sub unit lain');

        $this->postJson('/api/v1/sub-units', ['name' => 'B', 'afdeling_code' => 'AFD 04', 'description' => ''])
            ->assertJsonPath('errors.afdeling_code.0', 'Kode Afdeling tidak boleh berisi spasi');
    }

    public function test_delete_sub_unit_listed_in_placement_object_is_rejected(): void
    {
        $subUnit = SubUnit::factory()->create();
        PlacementObject::factory()->hasAttached($subUnit)->create(['name' => 'Tea Bridge']);

        $this->deleteJson("/api/v1/sub-units/{$subUnit->id}")
            ->assertConflict()
            ->assertJsonPath('success', false);
    }

    public function test_delete_unused_sub_unit(): void
    {
        $subUnit = SubUnit::factory()->create();

        $this->deleteJson("/api/v1/sub-units/{$subUnit->id}")->assertOk();
        $this->assertModelMissing($subUnit);
    }
}
