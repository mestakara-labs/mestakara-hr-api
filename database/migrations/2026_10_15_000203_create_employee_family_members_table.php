<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data batih / keluarga inti karyawan (ERD 3.2 — employee_family_members).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_family_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('relationship', 10);                  // App\Enums\FamilyRelationship
            $table->string('name', 150);
            $table->char('ktp_number', 16)->nullable();
            $table->string('gender', 10);                        // App\Enums\Gender
            $table->string('birth_place', 100)->nullable();
            $table->date('birth_date');
            $table->string('occupation', 100)->nullable();
            $table->string('dependent_status', 20);              // App\Enums\DependentStatus
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_family_members');
    }
};
