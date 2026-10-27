<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regu Kerja (ERD 3.4 & 3.5):
 *
 *   work_groups                     regu: pola, mode rotasi, Tanggal Mulai Berlaku Jadwal (anchor_date)
 *   work_group_members              anggota + slot saat bergabung; diakhiri dengan end_date (riwayat tidak dihapus)
 *   termination_ended_memberships   keanggotaan yang diakhiri Penonaktifan (untuk pembatalan)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150)->unique();
            $table->foreignUuid('sub_unit_id')->constrained('sub_units')->restrictOnDelete();
            $table->foreignUuid('placement_object_id')->nullable()->constrained('placement_objects')->nullOnDelete();
            $table->foreignUuid('work_pattern_id')->constrained('work_patterns')->restrictOnDelete();
            $table->foreignUuid('leader_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('rotation_mode', 10);                        // App\Enums\RotationMode
            $table->unsignedSmallInteger('rotate_every_days')->default(7);
            $table->date('anchor_date');                                // hari ke-1 pola & putaran ke-0
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('work_group_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_group_id')->constrained('work_groups')->restrictOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('start_slot_id')->constrained('pattern_slots')->restrictOnDelete();
            $table->date('start_date');                                 // tanggal bergabung
            $table->date('end_date')->nullable();                       // tanggal terakhir; NULL = aktif
            $table->timestamps();

            $table->index(['employee_id', 'end_date']);
        });

        Schema::create('termination_ended_memberships', function (Blueprint $table) {
            $table->foreignUuid('termination_item_id')->constrained('employee_termination_items')->cascadeOnDelete();
            $table->foreignUuid('work_group_member_id')->constrained('work_group_members')->cascadeOnDelete();
            $table->date('previous_end_date')->nullable();
            $table->primary(['termination_item_id', 'work_group_member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('termination_ended_memberships');
        Schema::dropIfExists('work_group_members');
        Schema::dropIfExists('work_groups');
    }
};
