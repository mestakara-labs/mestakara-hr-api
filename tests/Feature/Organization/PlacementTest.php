<?php

namespace Tests\Feature\Organization;

use App\Models\PlacementCategory;
use App\Models\PlacementObject;
use App\Models\SubUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian master Kategori Objek (/placement-categories) dan Objek Penempatan (/placement-objects).
 */
class PlacementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_create_category(): void
    {
        $this->postJson('/api/v1/placement-categories', ['name' => 'Penginapan', 'description' => ''])
            ->assertCreated()
            ->assertJsonPath('data.object_count', 0);
    }

    public function test_delete_category_used_by_object_is_rejected(): void
    {
        $category = PlacementCategory::factory()->create();
        PlacementObject::factory()->for($category, 'category')->create();

        $this->deleteJson("/api/v1/placement-categories/{$category->id}")->assertConflict();
    }

    public function test_create_object_in_several_sub_units(): void
    {
        $category = PlacementCategory::factory()->create(['name' => 'Wisata Alam']);
        [$gunungMas, $malabar] = SubUnit::factory()->count(2)->create();

        $this->postJson('/api/v1/placement-objects', [
            'name' => 'Tea Bridge',
            'category_id' => $category->id,
            'sub_unit_ids' => [$gunungMas->id, $malabar->id, $malabar->id],
            'description' => '',
        ])
            ->assertCreated()
            ->assertJsonPath('data.category_name', 'Wisata Alam')
            ->assertJsonCount(2, 'data.sub_units')
            ->assertJsonPath('data.sub_units.0.id', $gunungMas->id);
    }

    public function test_create_object_requires_category_and_sub_unit(): void
    {
        $this->postJson('/api/v1/placement-objects', ['name' => 'ATV', 'category_id' => '', 'sub_unit_ids' => [], 'description' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('errors.category_id.0', 'Pilih Kategori')
            ->assertJsonPath('errors.sub_unit_ids.0', 'Pilih minimal satu Sub Unit');
    }

    public function test_create_object_with_unknown_sub_unit_is_rejected(): void
    {
        $category = PlacementCategory::factory()->create();

        $this->postJson('/api/v1/placement-objects', [
            'name' => 'ATV',
            'category_id' => $category->id,
            'sub_unit_ids' => ['tidak-ada'],
            'description' => '',
        ])->assertJsonPath('errors.sub_unit_ids.0', 'Sub unit tidak ditemukan');
    }

    public function test_update_object_replaces_sub_units(): void
    {
        [$first, $second] = SubUnit::factory()->count(2)->create();
        $object = PlacementObject::factory()->hasAttached($first)->create();

        $this->putJson("/api/v1/placement-objects/{$object->id}", [
            'name' => $object->name,
            'category_id' => $object->category_id,
            'sub_unit_ids' => [$second->id],
            'description' => '',
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data.sub_units')
            ->assertJsonPath('data.sub_units.0.id', $second->id);
    }
}
