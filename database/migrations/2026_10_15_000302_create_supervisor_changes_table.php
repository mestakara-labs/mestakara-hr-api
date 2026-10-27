<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat perubahan atasan (ERD 3.3):
 *
 *   supervisor_changes          siapa, kapan, sumber (Manual / Mutasi / Penonaktifan), keterangan
 *   supervisor_change_details   satu baris per atasan: side = "Sebelum" atau "Sesudah"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervisor_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('assignment_id')->constrained('position_assignments')->cascadeOnDelete();
            $table->string('source', 100);                          // "Manual", "Mutasi <nomor>", "Penonaktifan <nomor>"
            // Usulan penyebabnya. Foreign key dipasang di migration tabel usulan (Tahap 5 & 6).
            $table->uuid('transfer_id')->nullable()->index();
            $table->uuid('termination_id')->nullable()->index();
            // Diisi kalau bawahan dialihkan KE ATAS karena jabatan atasannya kosong (dimutasi tanpa pengganti).
            // Dipakai fitur "Kembalikan bawahan" saat jabatan itu terisi lagi. (Tambahan di luar ERD v1.5.)
            $table->foreignUuid('vacated_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignUuid('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();
        });

        Schema::create('supervisor_change_details', function (Blueprint $table) {
            $table->foreignUuid('supervisor_change_id')->constrained('supervisor_changes')->cascadeOnDelete();
            $table->string('side', 10);                             // App\Enums\SupervisorChangeSide
            $table->foreignUuid('supervisor_assignment_id')->constrained('position_assignments')->cascadeOnDelete();
            $table->primary(['supervisor_change_id', 'side', 'supervisor_assignment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_change_details');
        Schema::dropIfExists('supervisor_changes');
    }
};
