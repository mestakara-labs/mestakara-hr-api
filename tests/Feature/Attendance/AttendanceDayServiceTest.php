<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceSource;
use App\Enums\AttendanceWriteOutcome;
use App\Enums\PayrollPeriodStatus;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayChange;
use App\Models\AttendanceDayChangeItem;
use App\Models\Employee;
use App\Models\OvertimeEntry;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\Attendance\AttendanceDayService;
use App\Services\Attendance\AttendanceSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Pengujian fondasi absensi: aturan prioritas sumber (manual > mobile > import_excel), konflik,
 * penguncian periode upah, jejak perubahan, dan ringkasan untuk perhitungan upah.
 */
class AttendanceDayServiceTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceDayService $service;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
        $this->service = app(AttendanceDayService::class);
        $this->employee = Employee::factory()->create(['employment_status' => 'Karyawan Casual', 'nik' => null]);
    }

    public function test_creates_a_day_and_records_the_change_with_details(): void
    {
        $outcome = $this->write(['status_code' => 'K', 'clock_in_at' => '2026-10-01 07:02:00'], AttendanceSource::ImportExcel);

        $this->assertSame(AttendanceWriteOutcome::Created, $outcome);
        $day = AttendanceDay::sole();
        $this->assertSame('K', $day->status_code->value);
        $this->assertSame(AttendanceSource::ImportExcel, $day->source);

        $change = AttendanceDayChange::sole();
        $this->assertSame(AttendanceWriteOutcome::Created, $change->action);
        $this->assertEqualsCanonicalizing(['status_code', 'clock_in_at'], $change->items()->pluck('field')->all());
        $this->assertNull($change->items()->where('field', 'status_code')->value('old_value'));
    }

    public function test_same_content_is_unchanged_and_not_recorded(): void
    {
        $this->write(['status_code' => 'K'], AttendanceSource::ImportExcel);

        $this->assertSame(AttendanceWriteOutcome::Unchanged, $this->write(['status_code' => 'K'], AttendanceSource::ImportExcel));
        $this->assertSame(1, AttendanceDayChange::count());
    }

    public function test_every_update_is_recorded_one_detail_per_changed_column(): void
    {
        $this->write(['status_code' => 'K', 'late_minutes' => 0], AttendanceSource::ImportExcel);

        $outcome = $this->write(['status_code' => 'S', 'late_minutes' => 15], AttendanceSource::ImportExcel);

        $this->assertSame(AttendanceWriteOutcome::Updated, $outcome);
        $change = AttendanceDayChange::where('action', AttendanceWriteOutcome::Updated)->sole();
        $this->assertSame(2, $change->items()->count());
        $item = $change->items()->where('field', 'status_code')->sole();
        $this->assertSame(['K', 'S'], [$item->old_value, $item->new_value]);
    }

    public function test_source_priority_manual_over_mobile_over_import(): void
    {
        $this->write(['status_code' => 'K'], AttendanceSource::ImportExcel);

        // mobile mengalahkan impor Excel
        $this->assertSame(AttendanceWriteOutcome::Updated, $this->write(['status_code' => 'M'], AttendanceSource::Mobile));
        $this->assertSame(AttendanceSource::Mobile, AttendanceDay::sole()->source);

        // manual mengalahkan mobile
        $this->assertSame(AttendanceWriteOutcome::Updated, $this->write(['status_code' => 'I'], AttendanceSource::Manual, 'Surat izin menyusul'));
        $this->assertSame(AttendanceSource::Manual, AttendanceDay::sole()->source);
        $this->assertSame('Surat izin menyusul', AttendanceDayChange::latest('created_at')->latest('id')->first()->reason);
    }

    public function test_weaker_source_with_different_content_is_a_conflict_and_does_not_overwrite(): void
    {
        $this->write(['status_code' => 'K'], AttendanceSource::Mobile);
        $changeCount = AttendanceDayChange::count();

        $this->assertSame(AttendanceWriteOutcome::Conflict, $this->write(['status_code' => 'M'], AttendanceSource::ImportExcel));
        $this->write(['status_code' => 'I'], AttendanceSource::Manual, 'Koreksi');
        $this->assertSame(AttendanceWriteOutcome::Conflict, $this->write(['status_code' => 'K'], AttendanceSource::Mobile));

        $this->assertSame('I', AttendanceDay::sole()->status_code->value);
        $this->assertSame($changeCount + 1, AttendanceDayChange::count());   // hanya koreksi manual yang tercatat
    }

    public function test_manual_correction_requires_a_reason(): void
    {
        $this->expectException(ValidationException::class);

        $this->write(['status_code' => 'K'], AttendanceSource::Manual, '  ');
    }

    public function test_locked_period_rejects_changes_from_any_source(): void
    {
        $this->write(['status_code' => 'K'], AttendanceSource::ImportExcel);
        PayrollPeriod::create(['period' => '2026-10', 'status' => PayrollPeriodStatus::Locked, 'locked_at' => now()]);

        foreach ([AttendanceSource::ImportExcel, AttendanceSource::Mobile, AttendanceSource::Manual] as $source) {
            $this->assertSame(AttendanceWriteOutcome::Locked, $this->write(['status_code' => 'S'], $source, 'Koreksi'));
        }
        $this->assertSame(AttendanceWriteOutcome::Locked, $this->service->write($this->employee->id, '2026-10-02', ['status_code' => 'K'], AttendanceSource::Mobile));

        $this->assertSame('K', AttendanceDay::sole()->status_code->value);
        $this->assertSame(1, AttendanceDayChange::count());

        // Bulan lain tidak terpengaruh.
        $this->assertSame(AttendanceWriteOutcome::Created, $this->service->write($this->employee->id, '2026-11-01', ['status_code' => 'K'], AttendanceSource::Mobile));
    }

    public function test_overtime_in_locked_period_cannot_be_added_or_changed(): void
    {
        $entry = $this->overtime('2026-10-03', 'reguler');
        PayrollPeriod::create(['period' => '2026-10', 'status' => PayrollPeriodStatus::Locked]);

        try {
            $entry->update(['description' => 'Ubah']);
            $this->fail('Lembur di periode terkunci seharusnya tidak bisa diubah.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->expectException(HttpException::class);
        $this->overtime('2026-10-04', 'reguler');
    }

    public function test_change_log_cannot_be_edited(): void
    {
        $this->write(['status_code' => 'K'], AttendanceSource::ImportExcel);

        $this->expectException(LogicException::class);
        AttendanceDayChangeItem::first()->update(['new_value' => 'M']);
    }

    public function test_summary_is_the_same_for_every_source(): void
    {
        $other = Employee::factory()->create();
        $this->service->write($this->employee->id, '2026-10-01', ['status_code' => 'K'], AttendanceSource::ImportExcel);
        $this->service->write($this->employee->id, '2026-10-02', ['status_code' => 'K'], AttendanceSource::Mobile);
        $this->service->write($this->employee->id, '2026-10-03', ['status_code' => 'S'], AttendanceSource::Manual, 'Sakit');
        $this->service->write($this->employee->id, '2026-10-31', ['status_code' => 'K'], AttendanceSource::Mobile);
        $this->service->write($this->employee->id, '2026-11-01', ['status_code' => 'K'], AttendanceSource::Mobile);   // bulan lain
        $this->overtime('2026-10-03', 'reguler');
        $this->overtime('2026-10-05', 'dw');

        $summary = app(AttendanceSummaryService::class)->forPeriod('2026-10', [$this->employee->id, $other->id]);

        $this->assertSame(3, $summary[$this->employee->id]['paid_days']);
        $this->assertSame(1, $summary[$this->employee->id]['codes']['S']);
        $this->assertSame(0, $summary[$this->employee->id]['codes']['DW']);
        $this->assertSame(['date' => '2026-10-03', 'start_time' => '17:00', 'end_time' => '01:00', 'duration_minutes' => 480, 'description' => 'Persiapan acara'],
            $summary[$this->employee->id]['regular_overtime'][0]);
        $this->assertCount(1, $summary[$this->employee->id]['dw_overtime']);
        $this->assertSame(0, $summary[$other->id]['paid_days']);
        $this->assertSame([], $summary[$other->id]['regular_overtime']);
    }

    private function write(array $values, AttendanceSource $source, ?string $reason = null): AttendanceWriteOutcome
    {
        return $this->service->write($this->employee->id, '2026-10-01', $values, $source, $reason);
    }

    private function overtime(string $date, string $type): OvertimeEntry
    {
        return OvertimeEntry::create([
            'employee_id' => $this->employee->id,
            'date' => $date,
            'start_time' => '17:00',
            'end_time' => '01:00',   // lewat tengah malam
            'duration_minutes' => OvertimeEntry::minutesBetween('17:00', '01:00'),
            'overtime_type' => $type,
            'description' => 'Persiapan acara',
            'source' => 'manual',
        ]);
    }
}
