<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penugasan jabatan / Jabatan Karyawan (ERD 3.3):
 *
 *   position_assignments           karyawan X memegang jabatan Y di sub unit Z dengan status S sejak TMT
 *   assignment_placement_objects   objek penempatan penugasan (boleh lebih dari satu)
 *   assignment_supervisors         atasan langsung = PENUGASAN milik atasan (boleh lebih dari satu)
 *
 * Riwayat tidak dihapus: penugasan diakhiri dengan mengisi end_date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('position_id')->constrained('positions')->restrictOnDelete();
            $table->foreignUuid('sub_unit_id')->constrained('sub_units')->restrictOnDelete();
            $table->string('assignment_status', 20);              // App\Enums\AssignmentStatus
            $table->date('start_date');                            // TMT mulai
            $table->date('end_date')->nullable();                  // TMT selesai; NULL = masih berjalan
            $table->string('decree_type', 30)->nullable();         // App\Enums\DecreeType
            $table->string('decree_number', 100)->nullable();
            $table->date('decree_date')->nullable();
            $table->foreignUuid('decree_file_id')->nullable()->constrained('files')->nullOnDelete();
            // Usulan mutasi pembuatnya. Foreign key dipasang di migration tabel transfers (Tahap 5).
            $table->uuid('transfer_id')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'start_date']);
        });

        Schema::create('assignment_placement_objects', function (Blueprint $table) {
            $table->foreignUuid('assignment_id')->constrained('position_assignments')->cascadeOnDelete();
            $table->foreignUuid('placement_object_id')->constrained('placement_objects')->restrictOnDelete();
            $table->primary(['assignment_id', 'placement_object_id']);
        });

        Schema::create('assignment_supervisors', function (Blueprint $table) {
            $table->foreignUuid('assignment_id')->constrained('position_assignments')->cascadeOnDelete();
            $table->foreignUuid('supervisor_assignment_id')->constrained('position_assignments')->cascadeOnDelete();
            $table->primary(['assignment_id', 'supervisor_assignment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_supervisors');
        Schema::dropIfExists('assignment_placement_objects');
        Schema::dropIfExists('position_assignments');
    }
};
