<?php

namespace Tests\Feature\Activation;

use App\Models\Employee;
use App\Models\EmployeeActivation;
use App\Models\EmployeeEducation;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\SubUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian Penambahan Karyawan (/employee-activations) dan status kepegawaian.
 */
class ActivationTest extends TestCase
{
    use RefreshDatabase;

    private Employee $newcomer;

    private PositionAssignment $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['name' => 'Admin SDM']));
        Storage::fake('local');

        // Karyawan baru dengan biodata lengkap (Belum Aktif) + satu riwayat pendidikan.
        $this->newcomer = Employee::factory()->create(['hire_date' => '2026-01-05']);
        EmployeeEducation::create(['employee_id' => $this->newcomer->id, 'level' => 'S1', 'graduation_year' => 2015]);

        $this->manager = PositionAssignment::factory()->atBrmLevel(1)->create();
    }

    /** Isian form usulan, bentuknya sama dengan front end. Bawaan: satu karyawan, Penerimaan Baru, TMT hari ini. */
    private function form(array $override = [], array $itemOverride = []): array
    {
        $item = array_merge([
            'id' => 'baru-1',
            'employee_id' => $this->newcomer->id,
            'type' => 'Penerimaan Baru',
            'effective_date' => Carbon::today()->toDateString(),
            'origin_unit' => '',
            'notes' => '',
            'has_placement' => false,
            'position_id' => '', 'assignment_status' => '', 'sub_unit_id' => '',
            'placement_object_ids' => [], 'supervisor_assignment_ids' => [], 'end_date' => '',
            'document_type' => '', 'document_number' => '', 'document_date' => '',
            'signer_source' => '', 'signer_assignment_id' => '', 'signer_name' => '', 'signer_position' => '',
            'keep_document_file' => false,
        ], $itemOverride);

        return array_merge([
            'document_scope' => 'Kolektif',
            'notes' => '',
            'action' => 'submit',
            'items' => json_encode([$item]),
        ], $override);
    }

    private function send(string $uri, array $data, string $method = 'post'): TestResponse
    {
        return $this->{$method}($uri, $data, ['Accept' => 'application/json']);
    }

    /** Rencana penempatan: Staf (BRM-4) di bawah Manager. */
    private function placement(): array
    {
        return [
            'has_placement' => true,
            'position_id' => Position::factory()->create(['brm_level' => 4])->id,
            'assignment_status' => 'Definitif',
            'sub_unit_id' => SubUnit::factory()->create()->id,
            'supervisor_assignment_ids' => [$this->manager->id],
        ];
    }

    public function test_new_employee_form_can_join_a_draft_activation(): void
    {
        $activationId = $this->send('/api/v1/employee-activations', $this->form(['action' => 'draft', 'items' => '[]']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'Draf')
            ->json('data.id');

        $this->send('/api/v1/employees', [
            'name' => 'Rina', 'employment_status' => 'Karyawan Casual', 'save_mode' => 'draft',
            'educations' => '[]', 'family_members' => '[]', 'activation_id' => $activationId,
        ])->assertCreated();

        $this->getJson("/api/v1/employee-activations/{$activationId}")
            ->assertJsonPath('data.items.0.employee.name', 'Rina')
            ->assertJsonPath('data.items.0.type', 'Penerimaan Baru')
            ->assertJsonPath('data.items.0.employee.active_status', 'Belum Aktif');
    }

    public function test_submit_makes_employee_active_and_creates_assignment(): void
    {
        $this->send('/api/v1/employee-activations', $this->form([], $this->placement()))
            ->assertCreated()
            ->assertJsonPath('data.status', 'Berlaku')
            ->assertJsonPath('data.missing_document_count', 1)
            ->assertJsonPath('data.items.0.employee.active_status', 'Aktif')
            ->assertJsonPath('data.items.0.placement.supervisors.0.assignment_id', $this->manager->id);

        $this->getJson("/api/v1/employees/{$this->newcomer->id}")
            ->assertJsonPath('data.active_status', 'Aktif')
            ->assertJsonPath('data.position.brm_level', 4)
            ->assertJsonPath('data.service_months', 0);

        $this->getJson("/api/v1/employees/{$this->newcomer->id}/status-history")
            ->assertJsonPath('data.0.kind', 'Penambahan')
            ->assertJsonPath('data.0.status', 'Berlaku');
    }

    public function test_future_tmt_stays_pending_until_daily_refresh(): void
    {
        $nextWeek = Carbon::today()->addWeek()->toDateString();

        $this->send('/api/v1/employee-activations', $this->form([], ['effective_date' => $nextWeek]))->assertCreated();
        $this->assertSame('Belum Aktif', $this->newcomer->fresh()->active_status);

        $this->travel(8)->days();
        $this->artisan('hr:refresh-employee-status')->assertSuccessful();

        $this->assertSame('Aktif', $this->newcomer->fresh()->active_status);
    }

    public function test_submit_requires_complete_employee_data(): void
    {
        $draft = Employee::factory()->create(['ktp_number' => null, 'religion' => null]);

        $errors = $this->send('/api/v1/employee-activations', $this->form([], ['employee_id' => $draft->id]))
            ->assertUnprocessable()
            ->json('errors');

        $this->assertStringStartsWith("Data {$draft->name} belum lengkap: No. KTP, Agama", $errors['items.baru-1.employee_id'][0]);
    }

    public function test_tmt_cannot_be_before_hire_date_and_transfer_in_needs_origin_unit(): void
    {
        $errors = $this->send('/api/v1/employee-activations', $this->form([], [
            'type' => 'Mutasi Masuk', 'effective_date' => '2025-12-31',
        ]))->json('errors');

        $this->assertSame('TMT Aktif tidak boleh sebelum Tanggal Masuk karyawan', $errors['items.baru-1.effective_date'][0]);
        $this->assertSame('Unit Asal wajib diisi', $errors['items.baru-1.origin_unit'][0]);
    }

    public function test_partly_filled_per_person_document_must_be_complete(): void
    {
        $errors = $this->send('/api/v1/employee-activations', $this->form(['document_scope' => 'Per Orang'], [
            'document_type' => 'SK',
        ]))->json('errors');

        $this->assertSame('Nomor Dokumen wajib diisi', $errors['items.baru-1.document_number'][0]);
        $this->assertSame('Unggah file dokumen', $errors['items.baru-1.document_file'][0]);
        $this->assertSame('Pilih asal Penandatangan', $errors['items.baru-1.signer_source'][0]);
    }

    public function test_collective_document_with_file_is_saved_and_used_for_assignment(): void
    {
        $id = $this->send('/api/v1/employee-activations', $this->form([
            'document_type' => 'Memo', 'document_number' => 'M-01', 'document_date' => '2026-10-01',
            'document_file' => UploadedFile::fake()->create('memo.pdf', 20, 'application/pdf'),
        ], $this->placement()))
            ->assertCreated()
            ->assertJsonPath('data.document.file.name', 'memo.pdf')
            ->assertJsonPath('data.missing_document_count', 0)
            ->json('data.id');

        $this->get("/api/v1/employee-activations/{$id}/files")->assertOk();
        $this->assertSame('M-01', PositionAssignment::where('employee_id', $this->newcomer->id)->first()->decree_number);
    }

    public function test_cancel_requires_reason_and_returns_employee_to_pending(): void
    {
        $id = $this->send('/api/v1/employee-activations', $this->form([], $this->placement()))->json('data.id');

        $this->postJson("/api/v1/employee-activations/{$id}/cancel", ['reason' => ''])
            ->assertJsonPath('errors.reason.0', 'Alasan Pembatalan wajib diisi');

        $this->postJson("/api/v1/employee-activations/{$id}/cancel", ['reason' => 'Salah input'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Dibatalkan');

        $this->assertSame('Belum Aktif', $this->newcomer->fresh()->active_status);
        $this->assertSame(0, PositionAssignment::where('employee_id', $this->newcomer->id)->count());

        // Karyawan yang pernah masuk usulan non-Draf tidak bisa dihapus permanen.
        $this->deleteJson("/api/v1/employees/{$this->newcomer->id}")->assertConflict();
    }

    public function test_cancel_is_blocked_when_new_assignment_has_subordinates(): void
    {
        $id = $this->send('/api/v1/employee-activations', $this->form([], $this->placement()))->json('data.id');
        $newAssignment = PositionAssignment::where('employee_id', $this->newcomer->id)->first();
        PositionAssignment::factory()->atBrmLevel(5)->create()->supervisors()->attach($newAssignment);

        $this->postJson("/api/v1/employee-activations/{$id}/cancel", ['reason' => 'Batal'])->assertConflict();
    }

    public function test_active_employee_cannot_be_activated_again(): void
    {
        $active = Employee::factory()->active()->create();

        $errors = $this->send('/api/v1/employee-activations', $this->form([], ['employee_id' => $active->id]))->json('errors');

        $this->assertSame("{$active->name} sudah aktif", $errors['items.baru-1.employee_id'][0]);
    }

    public function test_only_draft_can_be_updated_or_deleted(): void
    {
        $draftId = $this->send('/api/v1/employee-activations', $this->form(['action' => 'draft']))->json('data.id');
        $this->deleteJson("/api/v1/employee-activations/{$draftId}")->assertOk();
        $this->assertModelExists($this->newcomer);

        $effectiveId = $this->send('/api/v1/employee-activations', $this->form())->json('data.id');
        $this->send("/api/v1/employee-activations/{$effectiveId}", $this->form(), 'put')->assertConflict();
        $this->deleteJson("/api/v1/employee-activations/{$effectiveId}")->assertConflict();
        $this->assertNotNull(EmployeeActivation::find($effectiveId));
    }
}
