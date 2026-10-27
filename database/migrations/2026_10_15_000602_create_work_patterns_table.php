<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pola Kerja = tabel SLOT × HARI SIKLUS (ERD 3.4):
 *
 *   work_patterns      nama & panjang siklus (1–28 hari)
 *   pattern_slots      posisi A, B, C, ... (urutan = urutan putaran)
 *   pattern_slot_days  shift satu slot pada hari ke-n siklus (shift_id NULL = libur)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_patterns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150)->unique();
            $table->unsignedSmallInteger('cycle_days');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('pattern_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_pattern_id')->constrained('work_patterns')->cascadeOnDelete();
            $table->string('code', 3);
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort_order');
            $table->boolean('rotates')->default(true);
            $table->timestamps();

            // Kode unik per pola diperiksa aplikasi (WorkPatternRequest), bukan unique index,
            // supaya kode dua slot bisa ditukar (A <-> B) dalam satu kali simpan.
            $table->index(['work_pattern_id', 'code']);
        });

        Schema::create('pattern_slot_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pattern_slot_id')->constrained('pattern_slots')->cascadeOnDelete();
            $table->unsignedSmallInteger('day_index');                  // hari ke-1 … ke-n
            $table->foreignUuid('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();

            $table->unique(['pattern_slot_id', 'day_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pattern_slot_days');
        Schema::dropIfExists('pattern_slots');
        Schema::dropIfExists('work_patterns');
    }
};
