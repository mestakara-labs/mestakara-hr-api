<?php

namespace Tests\Feature\Schedule;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian master Shift (/shifts), Hari Libur (/holidays), dan Pola Kerja (/work-patterns).
 */
class ShiftPatternTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    private function shift(array $override = []): array
    {
        return array_merge([
            'code' => 'p', 'name' => 'Pagi', 'start_time' => '07:00', 'end_time' => '12:00',
            'break_minutes' => 0, 'late_tolerance_minutes' => 10,
            'checkin_window_before_minutes' => 120, 'checkout_window_after_minutes' => 240,
            'color' => '#1BAA4E', 'description' => '',
        ], $override);
    }

    public function test_create_night_shift_computes_duration_across_midnight(): void
    {
        $this->postJson('/api/v1/shifts', $this->shift(['code' => 'M', 'name' => 'Malam', 'start_time' => '22:00', 'end_time' => '06:00', 'break_minutes' => 60]))
            ->assertCreated()
            ->assertJsonPath('data.crosses_midnight', true)
            ->assertJsonPath('data.duration_minutes', 480)
            ->assertJsonPath('data.work_minutes', 420)
            ->assertJsonPath('data.start_time', '22:00');

        $this->postJson('/api/v1/shifts', $this->shift())->assertCreated()->assertJsonPath('data.code', 'P');
    }

    public function test_shift_validation_messages(): void
    {
        $this->postJson('/api/v1/shifts', $this->shift(['end_time' => '07:00', 'color' => 'hijau']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.end_time.0', 'Jam Selesai tidak boleh sama dengan Jam Mulai')
            ->assertJsonPath('errors.color.0', 'Pilih Warna atau isi kode warna yang benar');

        $this->postJson('/api/v1/shifts', $this->shift(['end_time' => '09:00', 'break_minutes' => 120]))
            ->assertJsonPath('errors.break_minutes.0', 'Istirahat harus lebih singkat dari lama shift');
    }

    public function test_holiday_date_must_be_unique(): void
    {
        $holiday = ['date' => '2026-08-17', 'name' => 'Hari Kemerdekaan', 'type' => 'Libur Nasional', 'description' => ''];
        $this->postJson('/api/v1/holidays', $holiday)->assertCreated();

        $this->postJson('/api/v1/holidays', [...$holiday, 'name' => 'Lain'])
            ->assertJsonPath('errors.date.0', 'Tanggal ini sudah tercatat sebagai "Hari Kemerdekaan"');
    }

    public function test_pattern_with_slots_and_rules(): void
    {
        $pagi = $this->postJson('/api/v1/shifts', $this->shift())->json('data.id');

        $this->postJson('/api/v1/work-patterns', [
            'name' => 'Dua Hari', 'cycle_days' => 2, 'description' => '',
            'slots' => [
                ['id' => 'baru-1', 'code' => 'a', 'name' => '', 'rotates' => true, 'shift_ids' => [$pagi]],
                ['id' => 'baru-2', 'code' => 'A', 'name' => '', 'rotates' => true, 'shift_ids' => [null, null]],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors', fn ($errors) => $errors['slots.0.shift_ids'][0] === 'Isi shift harus sepanjang siklus'
                && $errors['slots.1.code'][0] === 'Kode "A" dipakai lebih dari sekali'
                && $errors['slots.1.shift_ids'][0] === 'Slot ini libur setiap hari; isi minimal satu hari kerja');

        $this->postJson('/api/v1/work-patterns', [
            'name' => 'Dua Hari', 'cycle_days' => 2, 'description' => '',
            'slots' => [['id' => 'baru-1', 'code' => 'a', 'name' => '', 'rotates' => true, 'shift_ids' => [$pagi, null]]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.slots.0.code', 'A')
            ->assertJsonPath('data.slots.0.name', 'Posisi A')
            ->assertJsonPath('data.slots.0.shift_ids', [$pagi, null]);

        $this->getJson("/api/v1/shifts/{$pagi}")->assertJsonPath('data.pattern_count', 1);
        $this->deleteJson("/api/v1/shifts/{$pagi}")->assertConflict();
    }
}
