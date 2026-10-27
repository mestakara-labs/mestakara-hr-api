<?php

namespace Tests\Feature\Schedule;

use App\Models\Employee;
use App\Models\PositionAssignment;
use App\Models\Shift;
use App\Models\SubUnit;
use App\Models\User;
use App\Models\WorkGroupMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian Regu Kerja (/work-groups) dan Jadwal Kerja (/schedule-periods, /schedules).
 *
 * Contoh: pola 2 slot bergilir setiap hari (siklus 1 hari):
 *   Slot A = Pagi,  Slot B = Sore.
 *   Hari ke-0: Budi A (Pagi), Siti B (Sore). Hari ke-1: bertukar. Dst.
 */
class WorkScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Shift $morning;

    private Shift $afternoon;

    private string $patternId;

    private array $slotIds;

    private Employee $budi;

    private Employee $siti;

    private string $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['name' => 'Admin SDM']));

        $this->morning = Shift::create($this->shiftData('P', 'Pagi', '07:00', '12:00'));
        $this->afternoon = Shift::create($this->shiftData('S', 'Sore', '12:00', '17:00'));

        $pattern = $this->postJson('/api/v1/work-patterns', [
            'name' => 'Dua Posisi', 'cycle_days' => 1, 'description' => '',
            'slots' => [
                ['id' => 'baru-1', 'code' => 'A', 'name' => '', 'rotates' => true, 'shift_ids' => [$this->morning->id]],
                ['id' => 'baru-2', 'code' => 'B', 'name' => '', 'rotates' => true, 'shift_ids' => [$this->afternoon->id]],
            ],
        ])->json('data');
        $this->patternId = $pattern['id'];
        $this->slotIds = array_column($pattern['slots'], 'id', 'code');

        $this->budi = Employee::factory()->active()->create(['name' => 'Budi']);
        $this->siti = Employee::factory()->active()->create(['name' => 'Siti']);
        $this->anchor = Carbon::today()->startOfMonth()->toDateString();
    }

    private function shiftData(string $code, string $name, string $start, string $end): array
    {
        return [
            'code' => $code, 'name' => $name, 'start_time' => $start, 'end_time' => $end, 'break_minutes' => 0,
            'late_tolerance_minutes' => 10, 'checkin_window_before_minutes' => 120, 'checkout_window_after_minutes' => 240,
            'color' => '#1BAA4E',
        ];
    }

    /** Isian regu: Bergilir tiap 1 hari, Budi di slot A & Siti di slot B mulai awal bulan ini. */
    private function groupForm(array $override = []): array
    {
        return array_merge([
            'name' => 'Regu Uji', 'sub_unit_id' => SubUnit::factory()->create()->id, 'placement_object_id' => '',
            'work_pattern_id' => $this->patternId, 'leader_employee_id' => '',
            'rotation_mode' => 'Bergilir', 'rotate_every_days' => 1, 'anchor_date' => $this->anchor, 'description' => '',
            'members' => [
                ['employee_id' => $this->budi->id, 'start_slot_id' => $this->slotIds['A']],
                ['employee_id' => $this->siti->id, 'start_slot_id' => $this->slotIds['B']],
            ],
            'members_start_date' => $this->anchor,
        ], $override);
    }

    private function createGroup(): string
    {
        return $this->postJson('/api/v1/work-groups', $this->groupForm())->assertCreated()->json('data.id');
    }

    private function currentMonth(): string
    {
        return Carbon::today()->format('Y-m');
    }

    public function test_group_with_members_and_rotation_projection(): void
    {
        $id = $this->postJson('/api/v1/work-groups', $this->groupForm())
            ->assertCreated()
            ->assertJsonPath('message', 'Regu kerja berhasil ditambahkan, dengan 2 anggota baru.')
            ->assertJsonPath('data.active_member_count', 2)
            ->json('data.id');

        $day0 = $this->anchor;
        $day1 = Carbon::parse($this->anchor)->addDay()->toDateString();

        $rows = collect($this->getJson("/api/v1/work-groups/{$id}/projection?from={$day0}&to={$day1}")->assertOk()->json('data.rows'))
            ->keyBy('employee.name');

        $this->assertSame(['A', 'B'], array_column($rows['Budi']['cells'], 'slot_code'));
        $this->assertSame([$this->morning->id, $this->afternoon->id], array_column($rows['Budi']['cells'], 'shift_id'));
        $this->assertSame(['B', 'A'], array_column($rows['Siti']['cells'], 'slot_code'));
    }

    public function test_rotating_slot_can_only_have_one_holder_and_member_must_be_active(): void
    {
        $pending = Employee::factory()->create(['name' => 'Rina']);
        $third = Employee::factory()->active()->create(['name' => 'Joko']);

        $errors = $this->postJson('/api/v1/work-groups', $this->groupForm(['members' => [
            ['employee_id' => $this->budi->id, 'start_slot_id' => $this->slotIds['A']],
            ['employee_id' => $third->id, 'start_slot_id' => $this->slotIds['A']],
            ['employee_id' => $pending->id, 'start_slot_id' => $this->slotIds['B']],
        ]]))->assertUnprocessable()->json('errors');

        $this->assertSame('Slot A sudah dipegang Budi. Slot bergilir hanya boleh satu orang.', $errors['members.1.start_slot_id'][0]);
        $this->assertSame('Rina bukan karyawan Aktif', $errors['members.2.employee_id'][0]);
    }

    public function test_employee_can_only_be_active_in_one_group(): void
    {
        $this->createGroup();

        $errors = $this->postJson('/api/v1/work-groups', $this->groupForm(['name' => 'Regu Lain']))->json('errors');

        $this->assertSame('Budi masih aktif di regu "Regu Uji". Akhiri keanggotaannya dulu.', $errors['members.0.employee_id'][0]);
    }

    public function test_schedule_period_lifecycle_and_cell_changes(): void
    {
        $groupId = $this->createGroup();
        $daysInMonth = Carbon::today()->daysInMonth;

        $period = $this->postJson('/api/v1/schedule-periods', ['group_id' => $groupId, 'month' => $this->currentMonth()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'Draf')
            ->assertJsonPath('data.generated_by', 'Admin SDM')
            ->assertJsonPath('data.vacancy_days', 0)
            ->assertJsonCount($daysInMonth * 2, 'data.entries')
            ->json('data');

        // Bulan yang sama tidak bisa dibuat dua kali; bulan terlalu jauh ditolak.
        $this->postJson('/api/v1/schedule-periods', ['group_id' => $groupId, 'month' => $this->currentMonth()])->assertConflict();
        $this->postJson('/api/v1/schedule-periods', ['group_id' => $groupId, 'month' => Carbon::today()->addMonths(3)->format('Y-m')])->assertConflict();

        $this->postJson("/api/v1/schedule-periods/{$period['id']}/publish")->assertOk()->assertJsonPath('data.status', 'Terbit');

        $entry = $period['entries'][0];
        $this->putJson("/api/v1/schedules/{$entry['id']}", ['shift_id' => '', 'reason' => ''])
            ->assertJsonPath('errors.reason.0', 'Alasan wajib diisi karena jadwal sudah diterbitkan');

        $this->putJson("/api/v1/schedules/{$entry['id']}", ['shift_id' => '', 'reason' => 'Izin'])
            ->assertOk()
            ->assertJsonPath('data.shift_id', null)
            ->assertJsonPath('data.source', 'Manual')
            ->assertJsonPath('data.change_count', 1);

        $this->getJson("/api/v1/schedules/{$entry['id']}/changes")
            ->assertJsonPath('data.0.reason', 'Izin')
            ->assertJsonPath('data.0.changed_by', 'Admin SDM');

        $this->getJson("/api/v1/schedule-periods/{$period['id']}")
            ->assertJsonPath('data.manual_count', 1)
            ->assertJsonPath('data.vacancy_days', 1);

        $this->postJson("/api/v1/schedule-periods/{$period['id']}/lock")->assertOk();
        $this->putJson("/api/v1/schedules/{$entry['id']}", ['shift_id' => $this->morning->id, 'reason' => 'x'])->assertConflict();
        $this->deleteJson("/api/v1/schedule-periods/{$period['id']}")->assertConflict();
    }

    public function test_regenerate_after_member_leaves(): void
    {
        $groupId = $this->createGroup();
        $periodId = $this->postJson('/api/v1/schedule-periods', ['group_id' => $groupId, 'month' => $this->currentMonth()])->json('data.id');

        // Siti keluar setelah hari pertama bulan ini.
        $member = WorkGroupMember::where('employee_id', $this->siti->id)->first();
        $this->putJson("/api/v1/work-groups/{$groupId}/members/{$member->id}", ['end_date' => $this->anchor])->assertOk();

        $remainingDays = Carbon::today()->daysInMonth - 1;
        $this->getJson("/api/v1/schedule-periods/{$periodId}")->assertJsonPath('data.outdated_count', $remainingDays);

        $this->postJson("/api/v1/schedule-periods/{$periodId}/regenerate/preview", ['from_date' => $this->anchor, 'keep_manual' => true, 'reason' => ''])
            ->assertOk()
            ->assertJsonCount($remainingDays, 'data.removed');

        $this->postJson("/api/v1/schedule-periods/{$periodId}/regenerate", ['from_date' => $this->anchor, 'keep_manual' => true, 'reason' => ''])->assertOk();

        $this->getJson("/api/v1/schedule-periods/{$periodId}")
            ->assertJsonPath('data.outdated_count', 0)
            ->assertJsonCount(Carbon::today()->daysInMonth + 1, 'data.entries');
    }

    public function test_cron_creates_next_month_draft_on_the_20th(): void
    {
        $groupId = $this->createGroup();
        $this->travelTo(Carbon::today()->startOfMonth()->addDays(19));

        $this->artisan('hr:generate-schedules')->assertSuccessful();

        $nextMonth = Carbon::today()->startOfMonth()->addMonth()->format('Y-m');
        $this->getJson("/api/v1/schedule-periods?group_id={$groupId}&month={$nextMonth}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.generated_by', 'Sistem');
    }

    public function test_termination_ends_and_cancel_restores_group_membership(): void
    {
        Storage::fake('local');
        $this->createGroup();
        PositionAssignment::factory()->for($this->budi)->atBrmLevel(5)->create();
        $tomorrow = Carbon::tomorrow()->toDateString();

        $id = $this->post('/api/v1/employee-terminations', [
            'document_scope' => 'Kolektif', 'document_type' => 'Memo', 'document_number' => 'M-1', 'document_date' => '2026-10-01',
            'document_file' => UploadedFile::fake()->create('memo.pdf', 10, 'application/pdf'),
            'notes' => '', 'action' => 'submit',
            'items' => json_encode([['id' => 'baru-1', 'employee_id' => $this->budi->id, 'type' => 'Mengundurkan Diri', 'effective_date' => $tomorrow]]),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.items.0.impact.memberships.0.group_name', 'Regu Uji')
            ->assertJsonPath('data.items.0.impact.memberships.0.end_date', Carbon::today()->toDateString())
            ->json('data.id');

        $member = WorkGroupMember::where('employee_id', $this->budi->id)->first();
        $this->assertSame(Carbon::today()->toDateString(), $member->end_date->toDateString());

        $this->postJson("/api/v1/employee-terminations/{$id}/cancel", ['reason' => 'Batal'])->assertOk();
        $this->assertNull($member->fresh()->end_date);
    }
}
