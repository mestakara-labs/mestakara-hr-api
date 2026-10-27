<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambahan fondasi absensi untuk UNGGAH ABSENSI (Tahap 2, ERD 3.7):
 *
 *   attendance_day_changes       + employee_id & date, attendance_day_id boleh NULL: jejak tetap ada walaupun
 *                                  hari absensinya DIKOSONGKAN (baris attendance_days dihapus)
 *   attendance_import_batches    + deleted_count (hari / lembur yang dihapus oleh unggahan)
 *   attendance_import_conflicts  BARU (detail batch): tanggal yang tidak ditimpa karena sumbernya mobile/manual
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_day_changes', function (Blueprint $table) {
            $table->dropForeign(['attendance_day_id']);
        });

        Schema::table('attendance_day_changes', function (Blueprint $table) {
            $table->uuid('attendance_day_id')->nullable()->change();
            $table->foreign('attendance_day_id')->references('id')->on('attendance_days')->nullOnDelete();
            $table->foreignUuid('employee_id')->nullable()->after('attendance_day_id')->constrained('employees')->restrictOnDelete();
            $table->date('date')->nullable()->after('employee_id');

            $table->index(['employee_id', 'date']);
        });

        Schema::table('attendance_import_batches', function (Blueprint $table) {
            $table->unsignedInteger('deleted_count')->default(0)->after('updated_count');
        });

        Schema::create('attendance_import_conflicts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_batch_id')->constrained('attendance_import_batches')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('date');
            $table->string('current_value', 30)->nullable();            // kode / jam yang tersimpan
            $table->string('current_source', 20);                       // App\Enums\AttendanceSource
            $table->string('file_value', 30)->nullable();               // kode / jam di file (NULL = kosong)

            $table->index(['import_batch_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_import_conflicts');

        Schema::table('attendance_import_batches', function (Blueprint $table) {
            $table->dropColumn('deleted_count');
        });

        Schema::table('attendance_day_changes', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'date']);
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn('date');
            $table->dropForeign(['attendance_day_id']);
        });

        Schema::table('attendance_day_changes', function (Blueprint $table) {
            $table->uuid('attendance_day_id')->nullable(false)->change();
            $table->foreign('attendance_day_id')->references('id')->on('attendance_days')->restrictOnDelete();
        });
    }
};
