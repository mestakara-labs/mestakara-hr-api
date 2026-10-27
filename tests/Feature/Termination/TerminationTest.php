<?php

namespace Tests\Feature\Termination;

use App\Models\Employee;
use App\Models\EmployeeEducation;
use App\Models\PositionAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian Penonaktifan Karyawan (/employee-terminations).
 *
 * Struktur contoh:
 *
 *   Manager (BRM-1)              $manager
 *     └─ Asisten (BRM-3)         $assistant   <- karyawan yang dinonaktifkan ($leaver), punya akun login
 *          └─ Staf (BRM-4)       $staff
 */
class TerminationTest extends TestCase
{
    use RefreshDatabase;

    private PositionAssignment $manager;

    private PositionAssignment $assistant;

    private PositionAssignment $staff;

    private Employee $leaver;

    private User $leaverAccount;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['name' => 'Admin SDM']));
        Storage::fake('local');

        $this->manager = PositionAssignment::factory()->atBrmLevel(1)->create();
        $this->assistant = PositionAssignment::factory()->atBrmLevel(3)->create();
        $this->staff = PositionAssignment::factory()->atBrmLevel(4)->create();
        $this->assistant->supervisors()->attach($this->manager);
        $this->staff->supervisors()->attach($this->assistant);

        $this->leaver = $this->assistant->employee;
        $this->leaverAccount = User::factory()->create(['employee_id' => $this->leaver->id]);
    }

    /** Isian form usulan, bentuknya sama dengan front end. Bawaan: Mengundurkan Diri, TMT hari ini, dokumen kolektif lengkap. */
    private function form(array $override = [], array $itemOverride = []): array
    {
        $item = array_merge([
            'id' => 'baru-1',
            'employee_id' => $this->leaver->id,
            'type' => 'Mengundurkan Diri',
            'effective_date' => Carbon::today()->toDateString(),
            'reason' => '', 'destination_unit' => '', 'notes' => '',
            'document_type' => '', 'document_number' => '', 'document_date' => '',
            'signer_source' => '', 'signer_assignment_id' => '', 'signer_name' => '', 'signer_position' => '',
            'keep_document_file' => false,
        ], $itemOverride);

        return array_merge([
            'document_scope' => 'Kolektif',
            'document_type' => 'Memo',
            'document_number' => 'M-099',
            'document_date' => '2026-10-01',
            'document_file' => UploadedFile::fake()->create('memo.pdf', 20, 'application/pdf'),
            'notes' => '',
            'action' => 'submit',
            'items' => json_encode([$item]),
        ], $override);
    }

    private function send(string $uri, array $data): TestResponse
    {
        return $this->post($uri, $data, ['Accept' => 'application/json']);
    }

    private function supervisorIdsOf(PositionAssignment $assignment): array
    {
        return $assignment->supervisors()->pluck('position_assignments.id')->all();
    }

    public function test_submit_requires_document(): void
    {
        $this->send('/api/v1/employee-terminations', $this->form(['document_type' => '', 'document_number' => '', 'document_date' => '', 'document_file' => null]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.document_type.0', 'Pilih Jenis Dokumen')
            ->assertJsonPath('errors.document_file.0', 'Unggah file dokumen');
    }

    public function test_draft_can_be_saved_without_document(): void
    {
        $this->send('/api/v1/employee-terminations', $this->form(['action' => 'draft', 'document_type' => '', 'document_file' => null]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'Draf')
            // Perkiraan dampak: jabatan diakhiri kemarin, staf dialihkan ke Manager.
            ->assertJsonPath('data.items.0.impact.ended_assignments.0.assignment_id', $this->assistant->id)
            ->assertJsonPath('data.items.0.impact.subordinates.0.to.0.assignment_id', $this->manager->id);
    }

    public function test_submit_applies_impact_and_deactivates_employee_and_account(): void
    {
        $this->send('/api/v1/employee-terminations', $this->form())
            ->assertCreated()
            ->assertJsonPath('data.status', 'Berlaku')
            ->assertJsonPath('data.items.0.employee.active_status', 'Nonaktif')
            ->assertJsonPath('data.items.0.impact.ended_assignments.0.end_date', Carbon::yesterday()->toDateString())
            ->assertJsonPath('data.items.0.impact.subordinates.0.assignment_id', $this->staff->id);

        $this->assertSame(Carbon::yesterday()->toDateString(), $this->assistant->fresh()->end_date->toDateString());
        $this->assertSame([$this->manager->id], $this->supervisorIdsOf($this->staff));
        $this->assertFalse($this->leaverAccount->fresh()->is_active);

        $this->getJson("/api/v1/employees/{$this->leaver->id}")
            ->assertJsonPath('data.active_status', 'Nonaktif')
            ->assertJsonPath('data.inactive_since', Carbon::today()->toDateString());
        $this->getJson("/api/v1/position-assignments/{$this->staff->id}/supervisor-history")
            ->assertJsonPath('data.0.source', 'Penonaktifan M-099');
    }

    public function test_preview_shows_impact_without_saving(): void
    {
        $this->postJson('/api/v1/employee-terminations/preview', ['items' => [
            ['id' => 'baru-1', 'employee_id' => $this->leaver->id, 'effective_date' => Carbon::today()->toDateString()],
        ]])
            ->assertOk()
            ->assertJsonPath('data.baru-1.ended_assignments.0.assignment_id', $this->assistant->id)
            ->assertJsonPath('data.baru-1.memberships', []);

        $this->assertNull($this->assistant->fresh()->end_date);
    }

    public function test_type_rules_follow_employment_status_and_required_fields(): void
    {
        $contractor = Employee::factory()->active()->create(['employment_status' => 'Karyawan PKWT']);

        $errors = $this->send('/api/v1/employee-terminations', $this->form([], ['employee_id' => $contractor->id, 'type' => 'Pensiun']))->json('errors');
        $this->assertSame('Pensiun hanya untuk Karyawan Tetap', $errors['items.baru-1.type'][0]);

        $errors = $this->send('/api/v1/employee-terminations', $this->form([], ['type' => 'PHK']))->json('errors');
        $this->assertSame('Alasan wajib diisi', $errors['items.baru-1.reason'][0]);

        $errors = $this->send('/api/v1/employee-terminations', $this->form([], ['type' => 'Mutasi Keluar']))->json('errors');
        $this->assertSame('Unit Tujuan wajib diisi', $errors['items.baru-1.destination_unit'][0]);
    }

    public function test_pending_employee_cannot_be_terminated(): void
    {
        $pending = Employee::factory()->create();

        $errors = $this->send('/api/v1/employee-terminations', $this->form([], ['employee_id' => $pending->id]))->json('errors');

        $this->assertSame("{$pending->name} tidak sedang Aktif", $errors['items.baru-1.employee_id'][0]);
    }

    public function test_cancel_restores_assignments_supervisors_and_account(): void
    {
        $id = $this->send('/api/v1/employee-terminations', $this->form())->json('data.id');

        $this->postJson("/api/v1/employee-terminations/{$id}/cancel", ['reason' => 'Batal mengundurkan diri'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Dibatalkan')
            ->assertJsonPath('data.items.0.impact', null);

        $this->assertNull($this->assistant->fresh()->end_date);
        $this->assertSame([$this->assistant->id], $this->supervisorIdsOf($this->staff));
        $this->assertSame('Aktif', $this->leaver->fresh()->active_status);
        $this->assertTrue($this->leaverAccount->fresh()->is_active);
    }

    public function test_cancel_is_blocked_after_reactivation(): void
    {
        $this->travelTo(Carbon::today()->subMonths(2));
        $id = $this->send('/api/v1/employee-terminations', $this->form())->json('data.id');
        $this->travelBack();

        // Karyawan diaktifkan kembali lewat Penambahan.
        EmployeeEducation::create(['employee_id' => $this->leaver->id, 'level' => 'S1', 'graduation_year' => 2010]);
        $this->send('/api/v1/employee-activations', [
            'document_scope' => 'Kolektif', 'notes' => '', 'action' => 'submit',
            'items' => json_encode([[
                'id' => 'baru-1', 'employee_id' => $this->leaver->id, 'type' => 'Pengaktifan Kembali',
                'effective_date' => Carbon::today()->toDateString(), 'has_placement' => false,
            ]]),
        ])->assertCreated();

        $this->assertSame('Aktif', $this->leaver->fresh()->active_status);
        $this->postJson("/api/v1/employee-terminations/{$id}/cancel", ['reason' => 'Batal'])->assertConflict();
    }

    public function test_employee_in_draft_termination_cannot_be_transferred(): void
    {
        $this->send('/api/v1/employee-terminations', $this->form(['action' => 'draft']))->assertCreated();

        $errors = $this->send('/api/v1/transfers', [
            'type' => 'Rotasi', 'action' => 'draft',
            'items' => json_encode([['id' => 'baru-1', 'employee_id' => $this->leaver->id]]),
        ])->json('errors');

        $this->assertSame('Karyawan ini sedang ada di usulan Penonaktifan (Draf)', $errors['items.baru-1.employee_id'][0]);
    }
}
