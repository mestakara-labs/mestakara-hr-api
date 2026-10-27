<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mutasi & Penempatan (ERD 3.3):
 *
 *   transfers                         satu usulan = satu SK kolektif untuk satu/banyak karyawan
 *   transfer_items                    satu karyawan di usulan: penugasan lama yang diakhiri + rencana penugasan baru
 *   transfer_item_placement_objects   objek penempatan rencana penugasan baru
 *   transfer_item_supervisors         atasan (penugasan) rencana penugasan baru
 *   transfer_ended_assignments        penugasan lama yang diakhiri saat Berlaku (untuk pembatalan)
 *
 * Status: Draf -> Menunggu Dokumen -> Berlaku, atau Dibatalkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 30);                              // App\Enums\TransferType
            $table->string('status', 30);                            // App\Enums\TransferStatus
            $table->date('effective_date')->nullable();              // TMT berlaku
            $table->text('notes')->nullable();

            // Dokumen dasar (opsional): Memo / Nota Dinas / SK / ...
            $table->string('basis_document_type', 30)->nullable();   // App\Enums\DocumentType
            $table->string('basis_document_number', 100)->nullable();
            $table->date('basis_document_date')->nullable();
            $table->foreignUuid('basis_document_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->string('basis_signer_source', 20)->nullable();   // App\Enums\SignerSource
            $table->foreignUuid('basis_signer_assignment_id')->nullable()->constrained('position_assignments')->nullOnDelete();
            $table->string('basis_signer_name', 150)->nullable();
            $table->string('basis_signer_position', 150)->nullable();

            // SK mutasi. Nomor SK unik di antara usulan yang TIDAK dibatalkan (diperiksa aplikasi),
            // jadi di database cukup index biasa — nomor usulan yang dibatalkan boleh dipakai lagi.
            $table->string('decree_number', 100)->nullable()->index();
            $table->date('decree_date')->nullable();
            $table->string('signer_source', 20)->nullable();
            $table->foreignUuid('signer_assignment_id')->nullable()->constrained('position_assignments')->nullOnDelete();
            $table->string('signer_name', 150)->nullable();
            $table->string('signer_position', 150)->nullable();
            $table->string('document_mode', 20)->nullable();         // App\Enums\DocumentMode
            $table->foreignUuid('signed_file_id')->nullable()->constrained('files')->nullOnDelete();

            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('transfer_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transfer_id')->constrained('transfers')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('from_assignment_id')->nullable()->constrained('position_assignments')->nullOnDelete();
            // Jabatan, status & sub unit boleh kosong selama usulan masih Draf (isian belum lengkap);
            // wajib terisi sebelum usulan disimpan & dilanjutkan (diperiksa TransferRules).
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->restrictOnDelete();
            $table->string('assignment_status', 20)->nullable();
            $table->foreignUuid('sub_unit_id')->nullable()->constrained('sub_units')->restrictOnDelete();
            $table->date('end_date')->nullable();                    // TMT selesai penugasan baru (mis. Plh)
            $table->text('notes')->nullable();
            $table->boolean('restore_subordinates')->default(false);
            $table->foreignUuid('excerpt_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->foreignUuid('new_assignment_id')->nullable()->constrained('position_assignments')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('transfer_item_placement_objects', function (Blueprint $table) {
            $table->foreignUuid('transfer_item_id')->constrained('transfer_items')->cascadeOnDelete();
            $table->foreignUuid('placement_object_id')->constrained('placement_objects')->restrictOnDelete();
            $table->primary(['transfer_item_id', 'placement_object_id']);
        });

        Schema::create('transfer_item_supervisors', function (Blueprint $table) {
            $table->foreignUuid('transfer_item_id')->constrained('transfer_items')->cascadeOnDelete();
            $table->foreignUuid('supervisor_assignment_id')->constrained('position_assignments')->cascadeOnDelete();
            $table->primary(['transfer_item_id', 'supervisor_assignment_id']);
        });

        Schema::create('transfer_ended_assignments', function (Blueprint $table) {
            $table->foreignUuid('transfer_id')->constrained('transfers')->cascadeOnDelete();
            $table->foreignUuid('assignment_id')->constrained('position_assignments')->cascadeOnDelete();
            $table->date('previous_end_date')->nullable();
            $table->primary(['transfer_id', 'assignment_id']);
        });

        // Foreign key kolom transfer_id yang sudah dibuat di Tahap 4.
        Schema::table('position_assignments', function (Blueprint $table) {
            $table->foreign('transfer_id')->references('id')->on('transfers')->nullOnDelete();
        });
        Schema::table('supervisor_changes', function (Blueprint $table) {
            $table->foreign('transfer_id')->references('id')->on('transfers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supervisor_changes', function (Blueprint $table) {
            $table->dropForeign(['transfer_id']);
        });
        Schema::table('position_assignments', function (Blueprint $table) {
            $table->dropForeign(['transfer_id']);
        });

        Schema::dropIfExists('transfer_ended_assignments');
        Schema::dropIfExists('transfer_item_supervisors');
        Schema::dropIfExists('transfer_item_placement_objects');
        Schema::dropIfExists('transfer_items');
        Schema::dropIfExists('transfers');
    }
};
