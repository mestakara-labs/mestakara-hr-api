<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Sub Unit / afdeling (ERD 3.1 — sub_units), mis. "Agrowisata Gunung Mas" (AFD03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sub_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150)->unique();
            // Opsional; unik bila diisi (unique index MySQL mengizinkan banyak NULL).
            $table->string('afdeling_code', 20)->nullable()->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_units');
    }
};
