<?php

namespace Tests\Feature\Transfer;

use App\Models\Employee;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\SubUnit;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian Mutasi & Penempatan (/transfers).
 *
 * Struktur contoh di setiap pengujian:
 *
 *   Manager (BRM-1)              $manager
 *     └─ Asisten Umum (BRM-3)    $assistant   <- jabatan $assistantPosition
 *          └─ Staf (BRM-4)       $staff
 *
 *   $newcomer = karyawan Aktif yang belum punya jabatan.
 */
class TransferTest extends TestCase
{
    use RefreshDatabase;

    private PositionAssignment $manager;

    private PositionAssignment $assistant;

    private PositionAssignment $staff;

    private Position $assistantPosition;

    private Position $otherPosition;

    private SubUnit $subUnit;

    private Employee $newcomer;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['name' => 'Admin SDM']));
        Storage::fake('local');

        $this->subUnit = SubUnit::factory()->create();
        $this->manager = PositionAssignment::factory()->atBrmLevel(1)->for($this->subUnit)->create();
        $this->assistant = PositionAssignment::factory()->atBrmLevel(3)->for($this->subUnit)->create();
        $this->staff = PositionAssignment::factory()->atBrmLevel(4)->for($this->subUnit)->create();
        $this->assistant->supervisors()->attach($this->manager);
        $this->staff->supervisors()->attach($this->assistant);

        $this->assistantPosition = $this->assistant->position;
        $this->otherPosition = Position::factory()->create(['brm_level' => 3]);
        $this->newcomer = Employee::factory()->active()->create();
    }

    /** Isian form usulan, bentuknya sama dengan front end. Bawaan: Rotasi Asisten ke jabatan lain, jalur Template. */
    private function form(array $override = [], array $itemOverride = []): array
    {
        $item = array_merge([
            'id' => 'baru-1',
            'employee_id' => $this->assistant->employee_id,
            'from_assignment_id' => $this->assistant->id,
            'position_id' => $this->otherPosition->id,
            'assignment_status' => 'Definitif',
            'sub_unit_id' => $this->subUnit->id,
            'placement_object_ids' => [],
            'supervisor_assignment_ids' => [$this->manager->id],
            'end_date' => '',
            'notes' => '',
            'restore_subordinates' => false,
        ], $itemOverride);

        return array_merge([
            'type' => 'Rotasi',
            'effective_date' => Carbon::today()->toDateString(),
            'notes' => '',
            'decree_number' => '2A44/P/2026.10.15-001',
            'decree_date' => Carbon::today()->toDateString(),
            'signer_source' => 'Unit',
            'signer_assignment_id' => $this->manager->id,
            'signer_name' => '',
            'signer_position' => '',
            'document_mode' => 'Template',
            'action' => 'submit',
            'items' => json_encode([$item]),
        ], $override);
    }

    /** Kirim form multipart (file ikut terkirim) dan minta jawaban JSON. */
    private function send(string $uri, array $data): TestResponse
    {
        return $this->post($uri, $data, ['Accept' => 'application/json']);
    }

    private function signedFile(): UploadedFile
    {
        return UploadedFile::fake()->create('sk.pdf', 100, 'application/pdf');
    }

    /** Usulan Rotasi Asisten yang langsung Berlaku (jalur Unggah). Hasilnya id usulan. */
    private function effectiveTransfer(): string
    {
        return $this->send('/api/v1/transfers', $this->form(['document_mode' => 'Unggah', 'signed_file' => $this->signedFile()]))
            ->assertCreated()
            ->json('data.id');
    }

    private function supervisorIdsOf(PositionAssignment $assignment): array
    {
        return $assignment->supervisors()->pluck('position_assignments.id')->all();
    }

    public function test_draft_needs_only_type_and_employee(): void
    {
        $this->send('/api/v1/transfers', $this->form(['action' => 'draft', 'decree_number' => '', 'signer_source' => ''], ['position_id' => '']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'Draf')
            ->assertJsonPath('message', 'Draf usulan mutasi berhasil disimpan.');
    }

    public function test_template_path_waits_for_document_then_activates(): void
    {
        $id = $this->send('/api/v1/transfers', $this->form())
            ->assertCreated()
            ->assertJsonPath('data.status', 'Menunggu Dokumen')
            // Perkiraan: staf dialihkan ke Manager karena jabatan Asisten Umum kosong.
            ->assertJsonPath('data.supervisor_changes.0.assignment_id', $this->staff->id)
            ->assertJsonPath('data.supervisor_changes.0.to.0.name', $this->manager->employee->name)
            ->json('data.id');

        $this->get("/api/v1/transfers/{$id}/documents?type=sk&format=pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get("/api/v1/transfers/{$id}/documents?type=sk&format=doc")->assertOk()->assertHeader('Content-Type', 'application/msword');

        $this->send("/api/v1/transfers/{$id}/activate", ['signed_file' => $this->signedFile()])
            ->assertOk()
            ->assertJsonPath('data.status', 'Berlaku')
            ->assertJsonPath('data.signed_file.name', 'sk.pdf');

        $newAssignment = Transfer::find($id)->items()->first()->newAssignment;
        $this->assertSame($this->otherPosition->id, $newAssignment->position_id);
        $this->assertSame(Carbon::yesterday()->toDateString(), $this->assistant->fresh()->end_date->toDateString());
        $this->assertSame([$this->manager->id], $this->supervisorIdsOf($this->staff));

        $this->getJson("/api/v1/position-assignments/{$this->staff->id}/supervisor-history")
            ->assertJsonPath('data.0.source', 'Mutasi 2A44/P/2026.10.15-001');
        $this->getJson("/api/v1/position-assignments/{$newAssignment->id}")
            ->assertJsonPath('data.transfer.decree_number', '2A44/P/2026.10.15-001');
    }

    public function test_upload_path_is_effective_immediately(): void
    {
        $this->send('/api/v1/transfers', $this->form(['document_mode' => 'Unggah', 'signed_file' => $this->signedFile()]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'Berlaku')
            ->assertJsonPath('message', 'Usulan mutasi berhasil disimpan dan sudah berlaku.');
    }

    public function test_upload_path_requires_signed_file(): void
    {
        $this->send('/api/v1/transfers', $this->form(['document_mode' => 'Unggah']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.signed_file.0', 'Unggah scan SK yang sudah ditandatangani');
    }

    public function test_submit_validates_items_with_item_keys(): void
    {
        $errors = $this->send('/api/v1/transfers', $this->form([], ['from_assignment_id' => '', 'supervisor_assignment_ids' => []]))
            ->assertUnprocessable()
            ->json('errors');

        $this->assertSame('Pilih jabatan lama yang diakhiri', $errors['items.baru-1.from_assignment_id'][0]);
        $this->assertSame('Pilih minimal satu Atasan Langsung', $errors['items.baru-1.supervisor_assignment_ids'][0]);
    }

    public function test_initial_placement_only_for_employee_without_position(): void
    {
        $errors = $this->send('/api/v1/transfers', $this->form(['type' => 'Penempatan Awal'], ['from_assignment_id' => '']))->json('errors');
        $this->assertSame('Karyawan ini sudah punya jabatan aktif. Pakai jenis mutasi lain.', $errors['items.baru-1.employee_id'][0]);

        $this->send('/api/v1/transfers', $this->form(['type' => 'Penempatan Awal'], [
            'employee_id' => $this->newcomer->id, 'from_assignment_id' => '',
        ]))->assertCreated();
    }

    public function test_employee_in_open_transfer_cannot_be_used_again(): void
    {
        $this->send('/api/v1/transfers', $this->form())->assertCreated();

        $errors = $this->send('/api/v1/transfers', $this->form(['decree_number' => '2A44/P/2026.10.15-002']))->json('errors');
        $this->assertSame(
            'Karyawan ini sedang ada di usulan Rotasi No. 2A44/P/2026.10.15-001 (Menunggu Dokumen)',
            $errors['items.baru-1.employee_id'][0],
        );
    }

    public function test_decree_number_must_be_unique_and_signer_required(): void
    {
        $this->send('/api/v1/transfers', $this->form())->assertCreated();

        $this->send('/api/v1/transfers', $this->form(['signer_source' => ''], [
            'employee_id' => $this->staff->employee_id, 'from_assignment_id' => $this->staff->id,
        ]))
            ->assertJsonPath('errors.decree_number.0', 'Nomor SK "2A44/P/2026.10.15-001" sudah dipakai usulan lain')
            ->assertJsonPath('errors.signer_source.0', 'Pilih asal Penandatangan');
    }

    public function test_cancel_effective_transfer_requires_reason_and_reverts_everything(): void
    {
        $id = $this->effectiveTransfer();
        $newAssignmentId = Transfer::find($id)->items()->first()->new_assignment_id;

        $this->postJson("/api/v1/transfers/{$id}/cancel", ['reason' => ''])
            ->assertJsonPath('errors.reason.0', 'Alasan Pembatalan wajib diisi');

        $this->postJson("/api/v1/transfers/{$id}/cancel", ['reason' => 'Salah orang'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Dibatalkan')
            ->assertJsonPath('data.cancel_reason', 'Salah orang')
            ->assertJsonPath('data.cancelled_by', 'Admin SDM')
            ->assertJsonPath('data.supervisor_changes', []);

        $this->assertNull(PositionAssignment::find($newAssignmentId));
        $this->assertNull($this->assistant->fresh()->end_date);
        $this->assertSame([$this->assistant->id], $this->supervisorIdsOf($this->staff));
    }

    public function test_cancel_is_blocked_when_new_assignment_was_transferred_again(): void
    {
        $id = $this->effectiveTransfer();
        $newAssignmentId = Transfer::find($id)->items()->first()->new_assignment_id;

        // Usulan kedua (Draf) memutasi lagi penugasan baru tadi.
        $this->send('/api/v1/transfers', $this->form(['action' => 'draft', 'decree_number' => ''], [
            'from_assignment_id' => $newAssignmentId, 'position_id' => $this->assistantPosition->id,
        ]))->assertCreated();

        $this->postJson("/api/v1/transfers/{$id}/cancel", ['reason' => 'Batal'])->assertConflict();
    }

    public function test_restore_subordinates_to_new_holder_of_vacant_position(): void
    {
        // 1. Asisten dirotasi; jabatannya kosong -> staf naik ke Manager.
        $this->effectiveTransfer();

        // 2. Karyawan baru ditempatkan di jabatan Asisten yang kosong, dengan "Kembalikan bawahan".
        $newcomerItem = [
            'employee_id' => $this->newcomer->id, 'from_assignment_id' => '', 'position_id' => $this->assistantPosition->id,
        ];
        $this->postJson('/api/v1/transfers/preview', ['items' => [['id' => 'baru-1', ...$newcomerItem, 'restore_subordinates' => true]]])
            ->assertOk()
            ->assertJsonPath("data.restorable_counts.{$this->assistantPosition->id}", 1)
            ->assertJsonPath('data.supervisor_changes.0.to.0.is_new', true);

        $id = $this->send('/api/v1/transfers', $this->form([
            'type' => 'Penempatan Awal', 'decree_number' => '2A44/P/2026.10.15-002',
            'document_mode' => 'Unggah', 'signed_file' => $this->signedFile(),
        ], [...$newcomerItem, 'restore_subordinates' => true]))->assertCreated()->json('data.id');

        $newHolder = Transfer::find($id)->items()->first()->new_assignment_id;
        $this->assertSame([$newHolder], $this->supervisorIdsOf($this->staff));
    }

    public function test_only_draft_can_be_deleted(): void
    {
        $draftId = $this->send('/api/v1/transfers', $this->form(['action' => 'draft']))->json('data.id');
        $this->deleteJson("/api/v1/transfers/{$draftId}")->assertOk();

        $waitingId = $this->send('/api/v1/transfers', $this->form())->json('data.id');
        $this->deleteJson("/api/v1/transfers/{$waitingId}")->assertConflict();
    }

    public function test_excerpt_replaces_decree_file_of_new_assignment(): void
    {
        $id = $this->effectiveTransfer();
        $item = Transfer::find($id)->items()->first();

        $this->send("/api/v1/transfers/{$id}/items/{$item->id}/excerpt", [
            'excerpt_file' => UploadedFile::fake()->create('petikan.pdf', 50, 'application/pdf'),
        ])->assertOk()->assertJsonPath('data.items.0.excerpt_file.name', 'petikan.pdf');

        $this->assertSame($item->fresh()->excerpt_file_id, $item->newAssignment->fresh()->decree_file_id);
        $this->get("/api/v1/transfers/{$id}/documents?type=scan-petikan&item={$item->id}")->assertOk();
    }

    public function test_basis_document_requires_complete_fields_when_partly_filled(): void
    {
        $id = $this->send('/api/v1/transfers', $this->form())->json('data.id');

        $this->send("/api/v1/transfers/{$id}/basis-document", ['basis_document_type' => 'SK'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.basis_document_number.0', 'Nomor Dokumen wajib diisi')
            ->assertJsonPath('errors.basis_document_file.0', 'Unggah file dokumen')
            ->assertJsonPath('errors.basis_signer_source.0', 'Pilih asal Penandatangan');

        $this->send("/api/v1/transfers/{$id}/basis-document", [
            'basis_document_type' => 'Memo', 'basis_document_number' => 'M-007', 'basis_document_date' => '2026-10-01',
            'basis_document_file' => UploadedFile::fake()->create('memo.pdf', 20, 'application/pdf'),
        ])->assertOk()->assertJsonPath('data.basis_document_file.name', 'memo.pdf');
    }
}
