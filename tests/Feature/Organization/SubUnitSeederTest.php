<?php

namespace Tests\Feature\Organization;

use App\Models\SubUnit;
use Database\Seeders\SubUnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pengujian seeder master Sub Unit.
 */
class SubUnitSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_sub_units_with_afdeling_codes(): void
    {
        $this->seed(SubUnitSeeder::class);

        $this->assertSame(7, SubUnit::count());
        $this->assertDatabaseHas('sub_units', ['afdeling_code' => 'AFD02', 'name' => 'Kantor Induk']);
        $this->assertDatabaseHas('sub_units', ['afdeling_code' => 'AFD50', 'name' => 'Afdeling Gunung Mas']);
    }

    public function test_seeder_can_run_again_without_duplicates(): void
    {
        $this->seed(SubUnitSeeder::class);
        $this->seed(SubUnitSeeder::class);

        $this->assertSame(7, SubUnit::count());
    }
}
