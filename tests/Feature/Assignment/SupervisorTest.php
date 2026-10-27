<?php

namespace Tests\Feature\Assignment;

use App\Models\PositionAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian Ubah Atasan (POST /position-assignments/supervisors) dan Riwayat Atasan.
 */
class SupervisorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['name' => 'Admin SDM']);
        Sanctum::actingAs($this->user);
    }

    public function test_change_supervisor_of_several_assignments_and_record_history(): void
    {
        $oldBoss = PositionAssignment::factory()->atBrmLevel(2)->create();
        $newBoss = PositionAssignment::factory()->atBrmLevel(2)->create();
        $first = PositionAssignment::factory()->atBrmLevel(4)->create();
        $second = PositionAssignment::factory()->atBrmLevel(5)->create();
        $first->supervisors()->attach($oldBoss);

        $this->postJson('/api/v1/position-assignments/supervisors', [
            'assignment_ids' => [$first->id, $second->id],
            'supervisor_assignment_ids' => [$newBoss->id],
            'notes' => 'Reorganisasi',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Atasan 2 karyawan berhasil diubah.')
            ->assertJsonPath('data.0.supervisors.0.assignment_id', $newBoss->id);

        $this->getJson("/api/v1/position-assignments/{$first->id}/supervisor-history")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.from.0.assignment_id', $oldBoss->id)
            ->assertJsonPath('data.0.to.0.assignment_id', $newBoss->id)
            ->assertJsonPath('data.0.source', 'Manual')
            ->assertJsonPath('data.0.changed_by', 'Admin SDM')
            ->assertJsonPath('data.0.notes', 'Reorganisasi');
    }

    public function test_same_supervisor_is_rejected(): void
    {
        $boss = PositionAssignment::factory()->atBrmLevel(2)->create();
        $staff = PositionAssignment::factory()->atBrmLevel(4)->create();
        $staff->supervisors()->attach($boss);

        $this->postJson('/api/v1/position-assignments/supervisors', [
            'assignment_ids' => [$staff->id], 'supervisor_assignment_ids' => [$boss->id], 'notes' => '',
        ])->assertJsonPath('errors.supervisor_assignment_ids.0', 'Atasan yang dipilih sama dengan atasan sekarang');
    }

    public function test_supervisor_must_have_higher_brm_level_than_all_selected(): void
    {
        $boss = PositionAssignment::factory()->atBrmLevel(4)->create();
        $staff = PositionAssignment::factory()->atBrmLevel(5)->create();
        $peer = PositionAssignment::factory()->atBrmLevel(4)->create();

        $this->postJson('/api/v1/position-assignments/supervisors', [
            'assignment_ids' => [$staff->id, $peer->id], 'supervisor_assignment_ids' => [$boss->id], 'notes' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.supervisor_assignment_ids.0', 'Atasan Langsung harus penugasan aktif karyawan lain dengan level BRM lebih tinggi dari semua karyawan yang dipilih');
    }

    public function test_supervisor_is_required_below_brm_1(): void
    {
        $staff = PositionAssignment::factory()->atBrmLevel(3)->create();

        $this->postJson('/api/v1/position-assignments/supervisors', [
            'assignment_ids' => [$staff->id], 'supervisor_assignment_ids' => [], 'notes' => '',
        ])->assertJsonPath('errors.supervisor_assignment_ids.0', 'Pilih minimal satu Atasan Langsung');
    }

    public function test_finished_assignment_cannot_be_changed(): void
    {
        $boss = PositionAssignment::factory()->atBrmLevel(2)->create();
        $finished = PositionAssignment::factory()->atBrmLevel(4)->finished()->create();

        $this->postJson('/api/v1/position-assignments/supervisors', [
            'assignment_ids' => [$finished->id], 'supervisor_assignment_ids' => [$boss->id], 'notes' => '',
        ])->assertJsonPath('errors.assignment_ids.0', 'Atasan hanya bisa diubah untuk penugasan yang masih aktif');
    }

    public function test_empty_selection_is_rejected(): void
    {
        $this->postJson('/api/v1/position-assignments/supervisors', [
            'assignment_ids' => [], 'supervisor_assignment_ids' => [], 'notes' => '',
        ])->assertJsonPath('errors.assignment_ids.0', 'Pilih minimal satu karyawan');
    }
}
