<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Shift & Hari Libur (ERD 3.4):
 *
 *   shifts    jam kerja, mis. Pagi 07:00–12:00. Jam selesai ≤ jam mulai = lintas tengah malam.
 *   holidays  penanda di kalender jadwal (tidak otomatis meliburkan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 4);                                  // boleh sama dengan shift lain
            $table->string('name', 100)->unique();
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedSmallInteger('late_tolerance_minutes')->default(10);
            $table->unsignedSmallInteger('checkin_window_before_minutes')->default(120);
            $table->unsignedSmallInteger('checkout_window_after_minutes')->default(240);
            $table->char('color', 7);                                   // HEX, mis. #1BAA4E
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('date')->unique();
            $table->string('name', 150);
            $table->string('type', 30);                                 // App\Enums\HolidayType
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('shifts');
    }
};
