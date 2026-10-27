<?php

namespace Tests\Feature\Attendance;

use App\Enums\PayrollPeriodStatus;
use App\Models\Employee;
use App\Models\OvertimeEntry;
use App\Models\OvertimeEntryChange;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian ubah lembur per baris oleh admin (POST / PUT / DELETE /overtime-entries): hasilnya bersumber manual,
 * aturan sama dengan impor Tahap 2, dan setiap perubahan tercatat di jejak. Hari ini 20 Oktober 2026.
 */
class OvertimeEntryManualTest extends TestCase
{
    use AttendanceTestHelpers, RefreshDatabase;

    private Employee $ani;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 10:00:00');
        Sanctum::actingAs(User::factory()->create(['name' => 'Admin SDM']));
        $this->ani = $this->casual('Ani', '7761');
        PositionAssignment::factory()->for($this->ani)->for(Position::factory()->state(['title' => 'Pelaksana Wahana']))->create(['start_date' => '2026-01-01']);
    }

    public function test_create_manual_entry_with_warnings_and_trail(): void
    {
        $this->storeCode($this->ani, '2026-10-07', 'L');

        $this->postJson('/api/v1/overtime-entries', $this->payload(['date' => '2026-10-07', 'start_time' => '22:00', 'end_time' => '03:00']))
            ->assertCreated()
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.duration_minutes', 300)
            ->assertJsonPath('data.crosses_midnight', true)
            ->assertJsonPath('data.position', 'Pelaksana Wahana')
            ->assertJsonPath('data.warnings', ['Lebih dari 4 jam lembur pada tanggal ini (5 jam)', 'Tanggal ini berkode L (Libur) di daftar hadir']);

        $change = OvertimeEntryChange::sole();
        $this->assertSame(['Dibuat', 'manual', 'Lembur mendadak acara', 'Admin SDM'], [$change->action->value, $change->source->value, $change->reason, $change->changer->name]);
        $this->assertSame(['22:00', '03:00'], [
            $change->items()->where('field', 'start_time')->value('new_value'),
            $change->items()->where('field', 'end_time')->value('new_value'),
        ]);
    }

    public function test_create_rejects_overlap_blocked_day_short_reason_and_other_period(): void
    {
        $this->storeOvertime($this->ani, '2026-10-06', '17:00', '19:00', 'dw', 'rekomendasi_sistem');
        $this->storeCode($this->ani, '2026-10-08', 'S');

        $this->postJson('/api/v1/overtime-entries', $this->payload(['date' => '2026-10-06', 'start_time' => '18:00', 'end_time' => '20:00']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.start_time.0', 'Tumpang tindih dengan Lembur DW 17:00–19:00');
        $this->postJson('/api/v1/overtime-entries', $this->payload(['date' => '2026-10-08']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'Tanggal ini berkode S (Sakit) di daftar hadir');
        $this->postJson('/api/v1/overtime-entries', $this->payload(['reason' => 'oke']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'Alasan minimal 5 karakter');
        $this->postJson('/api/v1/overtime-entries', $this->payload(['date' => '2026-09-30']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'Tanggal di luar periode Oktober 2026');
    }

    public function test_update_turns_excel_or_recommendation_row_into_manual_and_records_old_new(): void
    {
        $entry = $this->storeOvertime($this->ani, '2026-10-06', '17:00', '18:00', 'dw', 'rekomendasi_sistem');

        $this->putJson("/api/v1/overtime-entries/{$entry->id}", [
            'date' => '2026-10-06', 'start_time' => '17:00', 'end_time' => '19:30', 'description' => 'Persiapan acara', 'reason' => 'Jam sebenarnya lebih lama',
        ])->assertOk()->assertJsonPath('data.source', 'manual')->assertJsonPath('data.duration_minutes', 150);

        $change = OvertimeEntryChange::where('action', 'Diubah')->sole();
        $items = $change->items()->get()->mapWithKeys(fn ($item) => [$item->field => [$item->old_value, $item->new_value]])->all();
        $this->assertSame(['18:00', '19:30'], $items['end_time']);
        $this->assertSame(['60', '150'], $items['duration_minutes']);
        $this->assertSame(['rekomendasi_sistem', 'manual'], $items['source']);
        $this->assertArrayNotHasKey('start_time', $items);   // tidak berubah, tidak dicatat
    }

    public function test_delete_requires_reason_and_keeps_trail(): void
    {
        $entry = $this->storeOvertime($this->ani, '2026-10-06', '17:00', '18:00');

        $this->deleteJson("/api/v1/overtime-entries/{$entry->id}", [])->assertUnprocessable()->assertJsonPath('errors.reason.0', 'Alasan wajib diisi');
        $this->deleteJson("/api/v1/overtime-entries/{$entry->id}", ['reason' => 'Lembur dibatalkan'])->assertOk();

        $this->assertFalse(OvertimeEntry::whereKey($entry->id)->exists());
        $change = OvertimeEntryChange::sole();
        $this->assertSame(['Dihapus', null, '2026-10-06'], [$change->action->value, $change->overtime_entry_id, $change->date->toDateString()]);
        $this->assertSame('17:00', $change->items()->where('field', 'start_time')->value('old_value'));
    }

    public function test_locked_period_rejects_create_update_and_delete(): void
    {
        $entry = $this->storeOvertime($this->ani, '2026-10-06', '17:00', '18:00');
        PayrollPeriod::create(['period' => '2026-10', 'status' => PayrollPeriodStatus::Locked]);
        $message = 'Periode upah Oktober 2026 sudah dikunci. Lembur bulan itu tidak bisa diubah.';

        $this->postJson('/api/v1/overtime-entries', $this->payload())->assertUnprocessable()->assertJsonPath('errors.period.0', $message);
        $this->putJson("/api/v1/overtime-entries/{$entry->id}", [...$this->payload(), 'reason' => 'Ubah jam'])->assertUnprocessable()->assertJsonPath('errors.period.0', $message);
        $this->deleteJson("/api/v1/overtime-entries/{$entry->id}", ['reason' => 'Hapus saja'])->assertUnprocessable()->assertJsonPath('errors.period.0', $message);
        $this->assertSame(0, OvertimeEntryChange::count());
    }

    private function payload(array $overrides = []): array
    {
        return [
            'period' => '2026-10', 'employee_id' => $this->ani->id, 'overtime_type' => 'reguler', 'date' => '2026-10-05',
            'start_time' => '17:00', 'end_time' => '19:00', 'description' => 'Persiapan acara', 'reason' => 'Lembur mendadak acara',
            ...$overrides,
        ];
    }
}
