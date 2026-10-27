<?php

namespace Tests\Feature\Organization;

use App\Models\Division;
use App\Models\OrganizationDecree;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian seeder struktur organisasi SK KEP/IV.2/1667/XI/2023.
 */
class OrganizationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_decree_divisions_and_positions(): void
    {
        $this->seed(OrganizationSeeder::class);

        $this->assertSame(OrganizationSeeder::DECREE_NUMBER, OrganizationDecree::active()?->decree_number);
        $this->assertSame(7, Division::count());
        $this->assertSame(168, Position::count());

        $additional = Position::where('code', 'JAB-136')->with('division')->first();
        $this->assertSame('Asisten SDM & Umum', $additional->title);
        $this->assertSame('UML', $additional->division->code);
        $this->assertSame(3, $additional->brm_level);

        $manager = Position::where('code', 'JAB-001')->first();
        $this->assertSame(1, $manager->brm_level);
        $this->assertNull($manager->division_id);

        // Setiap bidang dipimpin tepat satu Asisten Manager (BRM-2), kecuali Operasional:
        // AM Operasional + AM Operasional Wilayah I & II. Semua jabatan BRM-2+ punya bidang.
        Division::withCount(['positions' => fn ($query) => $query->where('brm_level', 2)])
            ->get()
            ->each(fn (Division $division) => $this->assertSame($division->code === 'OPS' ? 3 : 1, $division->positions_count, $division->name));
        $this->assertSame(0, Position::where('brm_level', '>=', 2)->whereNull('division_id')->count());
    }

    public function test_operational_regions_share_one_division_without_duplicate_positions(): void
    {
        $this->seed(OrganizationSeeder::class);

        $operational = Division::where('code', 'OPS')->firstOrFail();
        $this->assertSame('Operasional', $operational->name);
        $this->assertFalse(Division::whereIn('code', ['OPW-1', 'OPW-2'])->exists());
        $this->assertSame(1, $operational->positions()->where('title', 'Pelaksana Kebersihan')->count());
        $this->assertSame(1, $operational->positions()->where('title', 'Pengawas Pelayanan II')->count());
        $this->assertSame('OPS', Position::where('code', 'JAB-137')->with('division')->first()->division->code);
    }

    public function test_rerun_removes_old_regional_divisions_and_merged_positions(): void
    {
        $oldDivision = Division::create(['code' => 'OPW-2', 'name' => 'Operasional Wilayah II']);
        Position::create(['code' => 'JAB-097', 'title' => 'Pelaksana Kebersihan', 'division_id' => $oldDivision->id, 'brm_level' => 6]);

        $this->seed(OrganizationSeeder::class);

        $this->assertFalse(Position::where('code', 'JAB-097')->exists());
        $this->assertFalse(Division::where('code', 'OPW-2')->exists());
    }

    public function test_seeder_can_run_again_without_duplicates(): void
    {
        OrganizationDecree::create(['decree_number' => 'KEP/LAMA/2022', 'decree_date' => '2022-06-30', 'is_active' => true]);

        $this->seed(OrganizationSeeder::class);
        $this->seed(OrganizationSeeder::class);

        $this->assertSame(7, Division::count());
        $this->assertSame(168, Position::count());
        $this->assertSame(1, OrganizationDecree::where('is_active', true)->count());
        $this->assertSame(OrganizationSeeder::DECREE_NUMBER, OrganizationDecree::active()?->decree_number);
    }

    public function test_organization_endpoint_shows_seeded_decree(): void
    {
        $this->seed(OrganizationSeeder::class);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/organization')
            ->assertOk()
            ->assertJsonPath('data.decree_number', 'KEP/IV.2/1667/XI/2023')
            ->assertJsonPath('data.decree_date', '2023-11-09')
            ->assertJsonPath('data.positions.0.title', 'Manager')
            ->assertJsonCount(168, 'data.positions');
    }
}
