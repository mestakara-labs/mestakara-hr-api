<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel file unggahan terpusat (ERD 3.2 — files).
 *
 * SEMUA file (foto, ijazah, SK, dokumen dasar, petikan) dicatat di sini.
 * Tabel lain cukup menyimpan kolom *_file_id yang menunjuk ke tabel ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('disk', 30);                        // disk Laravel, mis. "local"
            $table->string('path', 500);                       // lokasi file di disk
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
