<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usulan Penambahan Karyawan (ERD 3.5):
 *
 *   employee_activations                 satu usulan (dokumen KOLEKTIF ada di sini)
 *   employee_activation_items            satu karyawan: dasar, TMT, dokumen PER ORANG, rencana penempatan
 *   activation_item_placement_objects    objek rencana penempatan
 *   activation_item_supervisors          atasan rencana penempatan
 *
 * Status: Draf -> Berlaku -> (bisa) Dibatalkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_activations', function (Blueprint $table) {
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

        Schema::create('employee_activation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('activation_id')->constrained('employee_activations')->cascadeOnDelete();
            // Draf karyawan yang dihapus ikut keluar dari usulan Draf (karyawan yang pernah Berlaku tidak bisa dihapus).
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('type', 30)->nullable();               // App\Enums\ActivationType (wajib saat Berlaku)
            $table->date('effective_date')->nullable();           // TMT aktif (wajib saat Berlaku)
            $table->string('origin_unit', 150)->nullable();       // wajib untuk Mutasi Masuk
            // Penonaktifan terakhir karyawan yang kembali. Foreign key dipasang di migration penonaktifan.
            $table->uuid('previous_termination_item_id')->nullable();
            $this->documentColumns($table);
            // Rencana penempatan (opsional): menjadi penugasan saat Berlaku.
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->restrictOnDelete();
            $table->string('assignment_status', 20)->nullable();
            $table->foreignUuid('sub_unit_id')->nullable()->constrained('sub_units')->restrictOnDelete();
            $table->date('assignment_end_date')->nullable();
            $table->foreignUuid('new_assignment_id')->nullable()->constrained('position_assignments')->nullOnDelete();
            $table->boolean('user_reactivated')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('activation_item_placement_objects', function (Blueprint $table) {
            $table->foreignUuid('activation_item_id')->constrained('employee_activation_items')->cascadeOnDelete();
            $table->foreignUuid('placement_object_id')->constrained('placement_objects')->restrictOnDelete();
            $table->primary(['activation_item_id', 'placement_object_id']);
        });

        Schema::create('activation_item_supervisors', function (Blueprint $table) {
            $table->foreignUuid('activation_item_id')->constrained('employee_activation_items')->cascadeOnDelete();
            $table->foreignUuid('supervisor_assignment_id')->constrained('position_assignments')->cascadeOnDelete();
            $table->primary(['activation_item_id', 'supervisor_assignment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activation_item_supervisors');
        Schema::dropIfExists('activation_item_placement_objects');
        Schema::dropIfExists('employee_activation_items');
        Schema::dropIfExists('employee_activations');
    }

    /** Kolom satu dokumen dasar + penandatangan (lihat App\Support\StatusDocument). */
    private function documentColumns(Blueprint $table): void
    {
        $table->string('document_type', 30)->nullable();          // App\Enums\DocumentType
        $table->string('document_number', 100)->nullable();
        $table->date('document_date')->nullable();
        $table->foreignUuid('document_file_id')->nullable()->constrained('files')->nullOnDelete();
        $table->string('signer_source', 20)->nullable();          // App\Enums\SignerSource
        $table->foreignUuid('signer_assignment_id')->nullable()->constrained('position_assignments')->nullOnDelete();
        $table->string('signer_name', 150)->nullable();
        $table->string('signer_position', 150)->nullable();
    }
};
