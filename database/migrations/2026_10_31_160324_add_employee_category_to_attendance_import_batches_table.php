<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unggah Absensi dipisah per KATEGORI KARYAWAN (App\Enums\EmployeeCategory): setiap batch unggahan & riwayatnya
 * milik satu kategori. Batch yang sudah ada = casual (sebelumnya Daily Worker belum punya unggahan sendiri).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_import_batches', function (Blueprint $table) {
            $table->string('employee_category', 20)->default('casual')->after('import_type');   // App\Enums\EmployeeCategory
            $table->index(['period', 'employee_category', 'import_type'], 'import_batches_period_category_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_import_batches', function (Blueprint $table) {
            $table->dropIndex('import_batches_period_category_type_index');
            $table->dropColumn('employee_category');
        });
    }
};
