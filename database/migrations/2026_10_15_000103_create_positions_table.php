<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Jabatan (ERD 3.1 — positions), mis. "JAB-015 Asisten Personalia".
 * Atasan TIDAK disimpan di jabatan, tetapi per penugasan (assignment_supervisors).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('title', 150);
            // NULL untuk jabatan BRM-1 (Manager). Bidang yang masih dipakai tidak bisa dihapus.
            $table->foreignUuid('division_id')->nullable()->constrained('divisions')->restrictOnDelete();
            $table->unsignedSmallInteger('brm_level');          // 1 (Manager) … 6 (Pelaksana)
            $table->unsignedSmallInteger('job_grade')->nullable(); // 1–99, opsional
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
