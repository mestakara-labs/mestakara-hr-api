<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PENANDA TANGAN dokumen Daftar Upah & SPKL (ERD 3.6), fleksibel per pemilik:
 *
 *   signature_settings          satu baris per pemilik: Umum, satu Kelompok Daftar Upah, atau satu Bidang; + tempat
 *   signature_sections          bagian tanda tangan per dokumen (mis. "Diperiksa Oleh"), urut di dokumen
 *   signature_section_signers   orang di satu bagian: karyawan (nama & jabatan diambil dari data terbaru) atau manual
 *
 * Dokumen tanpa bagian sama sekali = "pakai penanda tangan Umum"; tempat NULL = pakai tempat Umum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner_type', 20);                           // App\Enums\SignatureOwnerType
            $table->uuid('owner_id')->nullable();                       // NULL untuk Umum; id kelompok / bidang
            $table->string('place', 100)->nullable();                   // wajib untuk Umum
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id']);
        });

        Schema::create('signature_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('signature_setting_id')->constrained('signature_settings')->cascadeOnDelete();
            $table->string('document', 20);                             // App\Enums\SignatureDocument
            $table->unsignedSmallInteger('sort_order');
            $table->string('label', 100);                               // mis. "Diperiksa Oleh"

            $table->index(['signature_setting_id', 'document']);
        });

        Schema::create('signature_section_signers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('signature_section_id')->constrained('signature_sections')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->string('source', 10);                               // App\Enums\SignatorySource: karyawan / manual
            $table->foreignUuid('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->string('name', 150)->nullable();                    // hanya untuk manual
            $table->string('position', 150)->nullable();                // hanya untuk manual
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_section_signers');
        Schema::dropIfExists('signature_sections');
        Schema::dropIfExists('signature_settings');
    }
};
