<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat pendidikan karyawan (ERD 3.2 — employee_educations).
 * Pendidikan terakhir = jenjang tertinggi (dihitung, tidak disimpan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_educations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('level', 10);                         // App\Enums\EducationLevel
            $table->string('institution', 150)->nullable();
            $table->string('major', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->unsignedSmallInteger('start_year')->nullable();
            $table->unsignedSmallInteger('graduation_year');
            $table->string('gpa', 10)->nullable();
            $table->string('title_front', 30)->nullable();
            $table->string('title_back', 30)->nullable();
            $table->string('certificate_number', 100)->nullable();
            $table->foreignUuid('certificate_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_educations');
    }
};
