<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usulan Penonaktifan Karyawan (ERD 3.5):
 *
 *   employee_terminations           satu usulan (dokumen KOLEKTIF ada di sini)
 *   employee_termination_items      satu karyawan: jenis, TMT nonaktif, alasan / unit tujuan, dokumen PER ORANG
 *   termination_ended_assignments   jabatan yang diakhiri saat Berlaku (untuk pembatalan)
 *
 * (termination_ended_memberships — keanggotaan regu kerja — dibuat bersama tabel regu di Tahap 7.)
 * Status: Draf -> Berlaku -> (bisa) Dibatalkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_terminations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status', 20);                         // App\Enums\ProposalStatus
            $table->string('document_scope', 20);                 // App\Enums\DocumentScope
            $this->documentColumns($table);
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_termination_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('termination_id')->constrained('employee_terminations')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('type', 30)->nullable();               // App\Enums\TerminationType (wajib saat Berlaku)
            $table->date('effective_date')->nullable();           // TMT nonaktif = hari pertama tidak aktif
            $table->text('reason')->nullable();                   // wajib untuk PHK & Pensiun Dini
            $table->string('destination_unit', 150)->nullable();  // wajib untuk Mutasi Keluar
            $this->documentColumns($table);
            $table->boolean('user_deactivated')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('termination_ended_assignments', function (Blueprint $table) {
            $table->foreignUuid('termination_item_id')->constrained('employee_termination_items')->cascadeOnDelete();
            $table->foreignUuid('assignment_id')->constrained('position_assignments')->cascadeOnDelete();
            $table->date('previous_end_date')->nullable();
            $table->primary(['termination_item_id', 'assignment_id']);
        });

        // Foreign key kolom yang sudah dibuat lebih dulu.
        Schema::table('employee_activation_items', function (Blueprint $table) {
            $table->foreign('previous_termination_item_id')->references('id')->on('employee_termination_items')->nullOnDelete();
        });
        Schema::table('supervisor_changes', function (Blueprint $table) {
            $table->foreign('termination_id')->references('id')->on('employee_terminations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supervisor_changes', function (Blueprint $table) {
            $table->dropForeign(['termination_id']);
        });
        Schema::table('employee_activation_items', function (Blueprint $table) {
            $table->dropForeign(['previous_termination_item_id']);
        });

        Schema::dropIfExists('termination_ended_assignments');
        Schema::dropIfExists('employee_termination_items');
        Schema::dropIfExists('employee_terminations');
    }

    /** Kolom satu dokumen dasar + penandatangan (lihat App\Support\StatusDocument). */
    private function documentColumns(Blueprint $table): void
    {
        $table->string('document_type', 30)->nullable();
        $table->string('document_number', 100)->nullable();
        $table->date('document_date')->nullable();
        $table->foreignUuid('document_file_id')->nullable()->constrained('files')->nullOnDelete();
        $table->string('signer_source', 20)->nullable();
        $table->foreignUuid('signer_assignment_id')->nullable()->constrained('position_assignments')->nullOnDelete();
        $table->string('signer_name', 150)->nullable();
        $table->string('signer_position', 150)->nullable();
    }
};
