<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data jabatan ASLI (belum terstandarisasi) — hanya REFERENSI, data resmi tetap di position_assignments:
 *
 *   legacy_assignments     jabatan, objek/unit, & kedudukan asli tiap karyawan (satu data terakhir per karyawan)
 *   nomenclature_aliases   kamus: penulisan asli -> jabatan / objek penempatan / sub unit standar
 *
 * Keduanya dihubungkan lewat TEKS (raw_position = alias bertipe Jabatan, dst.), bukan foreign key,
 * supaya data asli tetap utuh walaupun kamusnya diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->unique()->constrained('employees')->cascadeOnDelete();
            $table->string('raw_position', 150)->nullable();      // mis. "Kary. Cleaning Service"
            $table->string('raw_placement', 150)->nullable();     // objek/unit asli, mis. "AGRO GUM"
            $table->string('raw_sub_unit', 150)->nullable();      // kedudukan asli, mis. "AFD.GUNUNG MAS"
            $table->string('raw_afdeling_code', 20)->nullable();  // kode afdeling saat ini menurut kedudukannya
            $table->string('source', 150)->nullable();            // asal data, mis. "Database Karyawan - Oktober 2026"
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('nomenclature_aliases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 30);                            // App\Enums\NomenclatureType
            $table->string('alias', 150);                          // penulisan asli
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignUuid('placement_object_id')->nullable()->constrained('placement_objects')->nullOnDelete();
            $table->foreignUuid('sub_unit_id')->nullable()->constrained('sub_units')->nullOnDelete();
            $table->string('status', 20);                          // App\Enums\AliasStatus
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['type', 'alias']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nomenclature_aliases');
        Schema::dropIfExists('legacy_assignments');
    }
};
