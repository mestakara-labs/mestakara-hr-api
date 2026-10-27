<?php

namespace Tests\Feature\Organization;

use App\Models\PlacementCategory;
use App\Models\PlacementObject;
use Database\Seeders\PlacementObjectSeeder;
use Database\Seeders\SubUnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pengujian seeder kategori objek & objek penempatan (sheet "Pemetaan Jabatan" & "Nomenklatur AGRO").
 */
class PlacementObjectSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_categories_objects_and_their_sub_units(): void
    {
        $this->seed([SubUnitSeeder::class, PlacementObjectSeeder::class]);

        $this->assertSame(['Administrasi', 'Food & Beverage', 'Penginapan', 'Ticket', 'Wahana'], PlacementCategory::orderBy('name')->pluck('name')->all());
        $this->assertSame(48, PlacementObject::count());

        $officeDago = PlacementObject::where('name', 'Office Dago')->with('category', 'subUnits')->sole();
        $this->assertSame('Administrasi', $officeDago->category->name);
        $this->assertSame(['Kantor Induk'], $officeDago->subUnits->pluck('name')->all());
        $this->assertSame(['Kantor Induk'], $this->subUnitsOf('Office F&B Bosscha'));
        $this->assertSame(['Malabar'], $this->subUnitsOf('Teh Jangkung'));
        $this->assertSame(['Ciater'], $this->subUnitsOf('Vila Merah'));

        // Semua objek Food & Beverage ada di Gunung Mas saja.
        $foodAndBeverage = PlacementObject::whereHas('category', fn ($query) => $query->where('name', 'Food & Beverage'))->with('subUnits')->get();
        $this->assertCount(10, $foodAndBeverage);
        $this->assertContains('Gamuhang', $foodAndBeverage->pluck('name')->all());
        $this->assertSame(['Gunung Mas'], $foodAndBeverage->flatMap->subUnits->pluck('name')->unique()->values()->all());
    }

    public function test_running_twice_does_not_duplicate_data(): void
    {
        $this->seed([SubUnitSeeder::class, PlacementObjectSeeder::class]);
        $this->seed(PlacementObjectSeeder::class);

        $this->assertSame(5, PlacementCategory::count());
        $this->assertSame(48, PlacementObject::count());
        $this->assertSame(48, DB::table('placement_object_sub_units')->count());
    }

    /** @return list<string> nama sub unit tempat objek tersedia */
    private function subUnitsOf(string $objectName): array
    {
        return PlacementObject::where('name', $objectName)->sole()->subUnits->pluck('name')->all();
    }
}
