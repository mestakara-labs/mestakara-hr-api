<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * JEJAK PERUBAHAN LEMBUR (ERD 3.7), pola sama dengan jejak absensi harian:
 *
 *   overtime_entry_changes       satu kejadian: Dibuat / Diubah / Dihapus / Rekomendasi sistem, sumber, alasan, siapa
 *   overtime_entry_change_items  detail: satu baris per isian yang berubah (nilai lama -> nilai baru, teks)
 *
 * employee_id, date, & overtime_type disimpan sendiri supaya jejak tetap ada walaupun barisnya dihapus
 * (overtime_entry_id jadi NULL). Keduanya tidak pernah diubah/dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_entry_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('overtime_entry_id')->nullable()->constrained('overtime_entries')->nullOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('date');
            $table->string('overtime_type', 10);                        // App\Enums\OvertimeType
            $table->string('action', 20);                               // App\Enums\OvertimeChangeAction
            $table->string('source', 20);                               // App\Enums\OvertimeSource: sumber yang melakukan perubahan
            $table->text('reason')->nullable();
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['employee_id', 'date']);
        });

        Schema::create('overtime_entry_change_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('overtime_entry_change_id')->constrained('overtime_entry_changes')->cascadeOnDelete();
            $table->string('field', 30);                                // mis. start_time, description
            $table->text('old_value')->nullable();                      // NULL = sebelumnya kosong / baris baru
            $table->text('new_value')->nullable();                      // NULL = dihapus
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_entry_change_items');
        Schema::dropIfExists('overtime_entry_changes');
    }
};
