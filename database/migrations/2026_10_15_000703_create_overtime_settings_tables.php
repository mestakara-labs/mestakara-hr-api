<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan Lembur (ERD 3.6):
 *
 *   overtime_settings          SATU baris: aturan perhitungan lembur & bawaan Lembur DW
 *   overtime_setting_signers   detail: 3 penandatangan SPKL + 3 penandatangan Daftar Upah (dipindah ke signature_settings, lihat 000902)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedSmallInteger('hourly_divisor')->default(173);          // upah sejam = upah sebulan / 173
            $table->decimal('first_hour_multiplier', 4, 2)->default(1.5);
            $table->decimal('next_hour_multiplier', 4, 2)->default(2);
            $table->unsignedTinyInteger('max_hours_per_day')->default(4);
            $table->unsignedTinyInteger('max_hours_per_week')->default(18);
            $table->unsignedInteger('rounding')->default(100);                     // pembulatan rupiah
            $table->time('dw_default_start_time')->default('17:00');
            $table->string('dw_default_description')->nullable();                  // wajib diisi admin
            $table->unsignedTinyInteger('time_step_minutes')->default(30);
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('overtime_setting_signers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('overtime_setting_id')->constrained('overtime_settings')->cascadeOnDelete();
            $table->string('document_type', 20);                        // SPKL / Daftar Upah
            $table->unsignedTinyInteger('sort_order');                  // 1–3, urutan di dokumen
            $table->string('role_label', 50);                           // mis. "Diperiksa"
            $table->string('position', 150);                            // mis. "Admin I Umum"
            $table->string('name', 150)->nullable();                    // nama pejabat; diisi admin
            $table->timestamps();

            $table->unique(['overtime_setting_id', 'document_type', 'sort_order'], 'overtime_setting_signers_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_setting_signers');
        Schema::dropIfExists('overtime_settings');
    }
};
