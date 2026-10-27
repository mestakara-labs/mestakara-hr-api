<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FONDASI DATA ABSENSI & LEMBUR (ERD 3.7) — dasar perhitungan upah Casual & Daily Worker.
 * Tabelnya TIDAK BERGANTUNG pada sumber data: impor Excel, aplikasi mobile, dan koreksi admin
 * masuk ke tabel yang sama (lihat App\Services\Attendance\AttendanceDayService).
 *
 *   payroll_periods              status periode upah (Draf / Dikunci)
 *   attendance_import_batches    riwayat unggahan Excel absensi & lembur
 *   attendance_events            LOG MENTAH scan aplikasi mobile (tidak pernah diubah/dihapus)
 *   attendance_days              SATU baris per karyawan per tanggal = dasar perhitungan upah
 *   attendance_day_changes       jejak setiap perubahan attendance_days (tidak pernah diubah/dihapus)
 *   attendance_day_change_items  detail jejak: satu baris per kolom yang berubah
 *   overtime_entries             lembur: satu karyawan, satu tanggal, satu rentang jam
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('period', 7)->unique();                        // YYYY-MM
            $table->string('status', 10);                               // App\Enums\PayrollPeriodStatus
            $table->timestamp('locked_at')->nullable();
            $table->foreignUuid('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('attendance_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('period', 7);                                  // YYYY-MM
            $table->string('import_type', 20);                          // App\Enums\AttendanceImportType
            $table->string('file_name');
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('conflict_count')->default(0);
            $table->string('status', 10);                               // App\Enums\ImportBatchStatus
            $table->timestamp('created_at')->nullable();

            $table->index(['period', 'import_type']);
        });

        Schema::create('attendance_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('event_type', 10);                           // App\Enums\AttendanceEventType
            $table->dateTime('occurred_at');                            // waktu server
            $table->dateTime('device_time')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy_m')->nullable();
            $table->string('photo_path')->nullable();                   // swafoto
            $table->string('device_id', 100)->nullable();
            $table->foreignUuid('schedule_id')->nullable()->constrained('schedules')->nullOnDelete();
            $table->boolean('is_valid');
            $table->string('invalid_reason')->nullable();               // mis. "di luar area"
            $table->timestamp('created_at')->nullable();

            $table->index(['employee_id', 'occurred_at']);
        });

        Schema::create('attendance_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('date');
            $table->string('status_code', 3);                           // App\Enums\AttendanceCode
            $table->dateTime('clock_in_at')->nullable();
            $table->dateTime('clock_out_at')->nullable();
            $table->unsignedSmallInteger('worked_minutes')->nullable();
            $table->unsignedSmallInteger('late_minutes')->nullable();
            $table->unsignedSmallInteger('early_leave_minutes')->nullable();
            $table->foreignUuid('schedule_id')->nullable()->constrained('schedules')->nullOnDelete();
            $table->string('source', 20);                               // App\Enums\AttendanceSource
            $table->foreignUuid('import_batch_id')->nullable()->constrained('attendance_import_batches')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index('date');
        });

        Schema::create('attendance_day_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('attendance_day_id')->constrained('attendance_days')->restrictOnDelete();
            $table->string('action', 10);                               // App\Enums\AttendanceWriteOutcome: Dibuat / Diubah
            $table->string('source', 20);                               // App\Enums\AttendanceSource
            $table->foreignUuid('import_batch_id')->nullable()->constrained('attendance_import_batches')->nullOnDelete();
            $table->text('reason')->nullable();                         // wajib untuk koreksi manual
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('attendance_day_change_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('attendance_day_change_id')->constrained('attendance_day_changes')->cascadeOnDelete();
            $table->string('field', 30);                                // mis. status_code, clock_in_at
            $table->text('old_value')->nullable();                      // NULL = sebelumnya kosong
            $table->text('new_value')->nullable();
        });

        Schema::create('overtime_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');                                   // ≤ jam mulai = lewat tengah malam
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('overtime_type', 10);                        // App\Enums\OvertimeType
            $table->text('description');                                // uraian pekerjaan
            $table->string('source', 20);                               // App\Enums\OvertimeSource
            $table->foreignUuid('import_batch_id')->nullable()->constrained('attendance_import_batches')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_entries');
        Schema::dropIfExists('attendance_day_change_items');
        Schema::dropIfExists('attendance_day_changes');
        Schema::dropIfExists('attendance_days');
        Schema::dropIfExists('attendance_events');
        Schema::dropIfExists('attendance_import_batches');
        Schema::dropIfExists('payroll_periods');
    }
};
