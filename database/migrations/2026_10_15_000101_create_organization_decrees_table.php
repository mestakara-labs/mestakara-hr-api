<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SK dasar struktur organisasi (ERD 3.1 — organization_decrees), mis. KEP/IV.2/1667/XI/2023.
 * Hanya satu SK yang berlaku (is_active = true); nomornya tampil di judul bagan organisasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_decrees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('decree_number', 100)->unique();
            $table->date('decree_date');
            $table->boolean('is_active')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_decrees');
    }
};
