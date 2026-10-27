<?php

namespace Tests\Feature\Organization;

use App\Enums\AliasStatus;
use App\Enums\NomenclatureType;
use App\Models\Employee;
use App\Models\LegacyAssignment;
use App\Models\NomenclatureAlias;
use App\Models\Position;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LegacyAssignmentSeeder;
use Database\Seeders\NomenclatureAliasSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pengujian kamus nomenklatur (nomenclature_aliases) & jabatan asli karyawan (legacy_assignments).
 */
class NomenclatureSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_aliases_map_raw_names_to_standard_data(): void
    {
        $this->assertSame(186, NomenclatureAlias::where('type', NomenclatureType::Position)->count());
        $this->assertSame(23, NomenclatureAlias::where('type', NomenclatureType::PlacementObject)->count());
        $this->assertSame(10, NomenclatureAlias::where('type', NomenclatureType::SubUnit)->count());

        $cleaning = $this->alias(NomenclatureType::Position, 'Kary. Cleaning Service');
        $this->assertSame('Pelaksana Cleaning Service', $cleaning->position->title);
        $this->assertSame(AliasStatus::Mapped, $cleaning->status);

        $this->assertSame('Parkir', $this->alias(NomenclatureType::PlacementObject, 'PARKIR')->placementObject->name);
        $this->assertSame('Afdeling Gunung Mas', $this->alias(NomenclatureType::SubUnit, 'AFD.GUNUNG MAS')->subUnit->name);

        // Nama lokasi umum & kedudukan tanpa sub unit: ditulis apa adanya, tanpa padanan.
        foreach ([[NomenclatureType::PlacementObject, 'AGRO GUM'], [NomenclatureType::SubUnit, 'BANDUNG']] as [$type, $raw]) {
            $alias = $this->alias($type, $raw);
            $this->assertSame(AliasStatus::Unmapped, $alias->status);
            $this->assertNull($alias->placement_object_id ?? $alias->sub_unit_id);
        }
    }

    public function test_new_and_renamed_positions_follow_the_excel_naming(): void
    {
        $this->assertSame(168, Position::count());
        foreach (['Asisten Tata Usaha', 'Asisten Operasional Food & Beverage Wilayah I', 'Admin I SDM & Umum', 'Karyawan Parkir'] as $title) {
            $this->assertTrue(Position::where('title', $title)->exists(), $title);
        }
        $this->assertFalse(Position::where('title', 'Asisten Tata Usaha dan Keuangan')->exists());
        $assistant = Position::where('title', 'Asisten Diperbantukan')->with('division')->sole();
        $this->assertSame(3, $assistant->brm_level);
        $this->assertSame('OPS', $assistant->division->code);
    }

    public function test_legacy_assignment_keeps_raw_data_with_afdeling_code_from_its_kedudukan(): void
    {
        $this->assertSame(578, LegacyAssignment::count());

        $dzulfi = Employee::where('nik', '8006026')->sole()->legacyAssignment;
        $this->assertSame('Asisten Diperbantukan', $dzulfi->raw_position);
        $this->assertSame('GUNUNG MAS', $dzulfi->raw_sub_unit);
        $this->assertSame('AFD03', $dzulfi->raw_afdeling_code);
        $this->assertSame(LegacyAssignmentSeeder::SOURCE, $dzulfi->source);

        $this->assertSame('AFD50', LegacyAssignment::where('raw_sub_unit', 'AFD.GUNUNG MAS')->value('raw_afdeling_code'));
        $this->assertNull(LegacyAssignment::where('raw_sub_unit', 'BANDUNG')->value('raw_afdeling_code'));
    }

    public function test_one_legacy_assignment_per_employee_and_reruns_do_not_duplicate(): void
    {
        $this->seed([NomenclatureAliasSeeder::class, LegacyAssignmentSeeder::class]);
        $this->assertSame(578, LegacyAssignment::count());
        $this->assertSame(219, NomenclatureAlias::count());

        $this->expectException(QueryException::class);
        LegacyAssignment::create(['employee_id' => Employee::where('nik', '8006026')->value('id'), 'raw_position' => 'Ganda']);
    }

    public function test_legacy_assignment_is_removed_with_its_employee(): void
    {
        $employee = Employee::factory()->create();
        $legacy = LegacyAssignment::create(['employee_id' => $employee->id, 'raw_position' => 'Kary. Cleaning Service']);

        $employee->delete();

        $this->assertFalse(LegacyAssignment::whereKey($legacy->id)->exists());
    }

    private function alias(NomenclatureType $type, string $raw): NomenclatureAlias
    {
        return NomenclatureAlias::where('type', $type)->where('alias', $raw)->sole();
    }
}
