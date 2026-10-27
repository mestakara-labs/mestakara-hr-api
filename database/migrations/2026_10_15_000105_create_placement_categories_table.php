<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Kategori Objek Penempatan (ERD 3.1 — placement_categories), mis. "Penginapan", "Wahana".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('placement_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('placement_categories');
    }
};
