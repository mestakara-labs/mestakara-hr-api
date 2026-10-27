<?php

namespace Tests\Feature\Payroll;

use App\Enums\SignatureDocument;
use App\Enums\SignatureOwnerType;
use App\Models\Division;
use App\Models\Employee;
use App\Models\PayrollGroup;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\SignatureSetting;
use App\Models\User;
use App\Services\Payroll\SignatureBook;
use Database\Seeders\SignatureSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian Penanda Tangan Daftar Upah & SPKL: Umum, per Kelompok Daftar Upah, per Bidang, dan aturan pengganti.
 */
class SignatureSettingTest extends TestCase
{
    use RefreshDatabase;

    private PayrollGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['name' => 'Admin SDM']));
        $this->group = PayrollGroup::create(['name' => 'Gunung Mas', 'sort_order' => 1]);
    }

    public function test_seeder_creates_general_signers_once(): void
    {
        $this->seed(SignatureSettingSeeder::class);
        $this->seed(SignatureSettingSeeder::class);

        $this->assertSame(1, SignatureSetting::count());
        $this->getJson('/api/v1/signature-settings/general')
            ->assertOk()
            ->assertJsonPath('data.place', 'Gunung Mas')
            ->assertJsonCount(3, 'data.payroll_sections')
            ->assertJsonPath('data.payroll_sections.0', [
                'label' => 'Disetujui',
                'signers' => [['source' => 'manual', 'employee_id' => null, 'name' => 'M. TAUFIK SIREGAR SE', 'position' => 'Manajer']],
            ])
            ->assertJsonPath('data.spkl_sections.1.signers.0.name', 'ASEP ZAENAL M SP');
    }

    public function test_employee_signer_always_shows_latest_name_and_main_position(): void
    {
        $employee = Employee::factory()->active()->create(['name' => 'Iyus Koswara']);
        $assignment = PositionAssignment::factory()->create([
            'employee_id' => $employee->id, 'position_id' => Position::factory()->create(['title' => 'Admin Umum'])->id,
        ]);
        $noPosition = Employee::factory()->active()->create(['name' => 'Tanpa Jabatan']);

        $this->putJson('/api/v1/signature-settings/general', [
            'place' => 'Bandung',
            'payroll_sections' => [
                ['label' => 'Diperiksa Oleh', 'signers' => [
                    ['source' => 'karyawan', 'employee_id' => $employee->id],
                    ['source' => 'karyawan', 'employee_id' => $noPosition->id],
                ]],
                ['label' => 'Dibuat Oleh', 'signers' => [['source' => 'manual', 'name' => 'Ade', 'position' => 'Admin SDM']]],
            ],
            'spkl_sections' => [['label' => 'Disetujui', 'signers' => [['source' => 'manual', 'name' => 'Budi', 'position' => 'Manajer']]]],
        ])
            ->assertOk()
            ->assertJsonPath('data.place', 'Bandung')
            ->assertJsonPath('data.payroll_sections.0.signers', [
                ['source' => 'karyawan', 'employee_id' => $employee->id, 'name' => 'Iyus Koswara', 'position' => 'Admin Umum'],
                ['source' => 'karyawan', 'employee_id' => $noPosition->id, 'name' => 'Tanpa Jabatan', 'position' => '-'],
            ])
            ->assertJsonPath('data.updated_by', 'Admin SDM');

        $employee->update(['name' => 'Iyus K.']);
        $assignment->position->update(['title' => 'Admin I Umum']);

        $this->getJson('/api/v1/signature-settings/general')
            ->assertJsonPath('data.payroll_sections.0.signers.0.name', 'Iyus K.')
            ->assertJsonPath('data.payroll_sections.0.signers.0.position', 'Admin I Umum');
    }

    public function test_validates_sections_signers_and_general_requirements(): void
    {
        $this->putJson('/api/v1/signature-settings/general', [
            'place' => '',
            'payroll_sections' => [],
            'spkl_sections' => [
                ['label' => '', 'signers' => []],
                ['label' => 'Disetujui', 'signers' => [
                    ['source' => 'karyawan', 'employee_id' => '0199aaaa-0000-7000-8000-000000000000'],
                    ['source' => 'manual', 'name' => '', 'position' => str_repeat('x', 151)],
                ]],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.place.0', 'Tempat penandatanganan wajib diisi')
            ->assertJsonPath('errors.payroll_sections.0', 'Penanda tangan Daftar Upah minimal 1 bagian')
            ->assertJsonPath('errors', fn (array $errors) => $errors['spkl_sections.0.label'] === ['Label bagian penanda tangan SPKL wajib diisi']
                && $errors['spkl_sections.0.signers'] === ['Setiap bagian penanda tangan SPKL minimal berisi 1 orang']
                && $errors['spkl_sections.1.signers.0.employee_id'] === ['Karyawan penanda tangan SPKL tidak ditemukan']
                && $errors['spkl_sections.1.signers.1.name'] === ['Nama penanda tangan SPKL wajib diisi']
                && $errors['spkl_sections.1.signers.1.position'] === ['Jabatan penanda tangan SPKL maksimal 150 karakter']);

        // Kelompok: boleh null semua (= pakai Umum), tetapi kalau diisi minimal 1 bagian.
        $this->putJson("/api/v1/signature-settings/payroll-groups/{$this->group->id}", ['place' => null, 'payroll_sections' => null, 'spkl_sections' => null])
            ->assertOk()
            ->assertJsonPath('data.payroll_sections', null);
        $this->putJson("/api/v1/signature-settings/payroll-groups/{$this->group->id}", ['payroll_sections' => []])
            ->assertUnprocessable()
            ->assertJsonPath('errors.payroll_sections.0', 'Penanda tangan Daftar Upah minimal 1 bagian');
    }

    public function test_group_and_division_endpoints_list_counts_and_details(): void
    {
        $division = Division::factory()->create(['name' => 'Bidang Tanaman']);

        $this->putJson("/api/v1/signature-settings/payroll-groups/{$this->group->id}", [
            'place' => null,
            'payroll_sections' => [['label' => 'Diperiksa', 'signers' => [
                ['source' => 'manual', 'name' => 'A', 'position' => 'Mandor'],
                ['source' => 'manual', 'name' => 'B', 'position' => 'Krani'],
            ]]],
            'spkl_sections' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $this->group->id)
            ->assertJsonPath('data.name', 'Gunung Mas')
            ->assertJsonPath('data.place', null)
            ->assertJsonPath('data.spkl_sections', null)
            ->assertJsonPath('data.payroll_sections.0.signers.1.name', 'B');

        $this->getJson('/api/v1/signature-settings/payroll-groups')
            ->assertOk()
            ->assertJsonPath('data.0', [
                'id' => $this->group->id, 'name' => 'Gunung Mas', 'sort_order' => 1, 'place' => null,
                'payroll' => ['sections' => 1, 'signers' => 2], 'spkl' => null,
            ]);

        $this->putJson("/api/v1/signature-settings/divisions/{$division->id}", [
            'place' => 'Lembang',
            'payroll_sections' => null,
            'spkl_sections' => [['label' => 'Disetujui', 'signers' => [['source' => 'manual', 'name' => 'C', 'position' => 'Asisten']]]],
        ])->assertOk()->assertJsonPath('data.place', 'Lembang');

        $this->getJson('/api/v1/signature-settings/divisions')
            ->assertOk()
            ->assertJsonPath('data.0.payroll', null)
            ->assertJsonPath('data.0.spkl', ['sections' => 1, 'signers' => 1]);
        $this->getJson("/api/v1/signature-settings/divisions/{$division->id}")
            ->assertOk()->assertJsonPath('data.spkl_sections.0.label', 'Disetujui');
    }

    public function test_empty_arrangement_and_place_fall_back_to_general(): void
    {
        $this->seed(SignatureSettingSeeder::class);
        $this->putJson("/api/v1/signature-settings/payroll-groups/{$this->group->id}", [
            'place' => null,
            'payroll_sections' => null,
            'spkl_sections' => [['label' => 'Disetujui', 'signers' => [['source' => 'manual', 'name' => 'C', 'position' => 'Mandor']]]],
        ])->assertOk();

        $book = SignatureBook::live();
        $payroll = $book->resolve(SignatureOwnerType::PayrollGroup, $this->group->id, SignatureDocument::Payroll);
        $spkl = $book->resolve(SignatureOwnerType::PayrollGroup, $this->group->id, SignatureDocument::Spkl);
        $none = $book->resolve(SignatureOwnerType::PayrollGroup, null, SignatureDocument::Spkl);

        $this->assertTrue($payroll['inherited']);
        $this->assertSame('Gunung Mas', $payroll['place']);
        $this->assertSame('M. TAUFIK SIREGAR SE', $payroll['sections'][0]['signers'][0]['name']);
        $this->assertFalse($spkl['inherited']);
        $this->assertSame([['label' => 'Disetujui', 'signers' => [['name' => 'C', 'position' => 'Mandor']]]], $spkl['sections']);
        $this->assertTrue($none['inherited']);
        $this->assertSame('IYUS KOSWARA', $none['sections'][0]['signers'][0]['name']);
    }
}
