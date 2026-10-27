<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Data Dasar Upah (ERD 3.6):
 *
 *   cost_centers     kode biaya SAP, mis. 4A44AGR007 "Tiket & Parkir"
 *   payroll_groups   Kelompok Daftar Upah, mis. "Gunung Mas", "Petugas Parkir" (urutan = urutan di daftar upah)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name', 150);                                // boleh sama dengan cost center lain
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_groups');
        Schema::dropIfExists('cost_centers');
    }
};
