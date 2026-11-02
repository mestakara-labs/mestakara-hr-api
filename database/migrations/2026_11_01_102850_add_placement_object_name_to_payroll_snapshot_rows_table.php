<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ekspor Daftar Upah & Payroll dipecah per OBJEK PENEMPATAN, jadi potret periode yang dikunci ikut menyimpan nama
 * objeknya (beberapa objek dipisah koma). Potret lama = kosong (tampil di bagian "LAINNYA" sampai dikunci ulang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_snapshot_rows', function (Blueprint $table) {
            $table->string('placement_object_name', 255)->nullable()->after('afdeling_code');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_snapshot_rows', function (Blueprint $table) {
            $table->dropColumn('placement_object_name');
        });
    }
};
