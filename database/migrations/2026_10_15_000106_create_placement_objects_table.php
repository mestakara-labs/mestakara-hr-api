<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Objek Penempatan (ERD 3.1 — placement_objects + pivot placement_object_sub_units).
 *
 * Satu objek (mis. "Tea Bridge") bisa tersedia di beberapa sub unit (Gunung Mas, Malabar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('placement_objects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->constrained('placement_categories')->restrictOnDelete();
            $table->string('name', 150)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('placement_object_sub_units', function (Blueprint $table) {
            $table->foreignUuid('placement_object_id')->constrained('placement_objects')->cascadeOnDelete();
            $table->foreignUuid('sub_unit_id')->constrained('sub_units')->restrictOnDelete();
            $table->primary(['placement_object_id', 'sub_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('placement_object_sub_units');
        Schema::dropIfExists('placement_objects');
    }
};
