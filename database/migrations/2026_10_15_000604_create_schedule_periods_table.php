<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jadwal Kerja resmi (ERD 3.4):
 *
 *   schedule_periods   jadwal satu regu untuk satu bulan: Draf -> Terbit -> Terkunci
 *   schedules          satu baris per anggota per tanggal (dasar absensi); jam disalin dari shift
 *   schedule_changes   riwayat ubahan satu sel jadwal
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_group_id')->constrained('work_groups')->restrictOnDelete();
            $table->char('month', 7);                                   // YYYY-MM
            $table->string('status', 10);                               // App\Enums\SchedulePeriodStatus
            $table->timestamp('generated_at');
            $table->foreignUuid('generated_by')->nullable()->constrained('users')->nullOnDelete(); // NULL = Sistem (cron)
            $table->timestamp('published_at')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->foreignUuid('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['work_group_id', 'month']);
        });

        Schema::create('schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('schedule_period_id')->constrained('schedule_periods')->cascadeOnDelete();
            $table->foreignUuid('work_group_member_id')->constrained('work_group_members')->restrictOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('work_date');
            $table->foreignUuid('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();          // NULL = libur
            $table->dateTime('planned_start')->nullable();
            $table->dateTime('planned_end')->nullable();                // bisa keesokan hari (shift malam)
            $table->string('slot_code', 3)->nullable();
            $table->foreignUuid('generated_shift_id')->nullable()->constrained('shifts')->restrictOnDelete(); // shift menurut pola
            $table->string('source', 10);                               // App\Enums\ScheduleSource
            $table->timestamps();

            $table->unique(['schedule_period_id', 'work_group_member_id', 'work_date'], 'schedules_period_member_date_unique');
            $table->index(['employee_id', 'work_date']);
        });

        Schema::create('schedule_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('schedule_id')->constrained('schedules')->cascadeOnDelete();
            $table->foreignUuid('old_shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->foreignUuid('new_shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->foreignUuid('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_changes');
        Schema::dropIfExists('schedules');
        Schema::dropIfExists('schedule_periods');
    }
};
