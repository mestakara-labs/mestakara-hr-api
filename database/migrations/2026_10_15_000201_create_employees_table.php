<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biodata karyawan (ERD 3.2 — employees).
 *
 * Selama Belum Aktif (draf dari wizard Penambahan) hanya name & employment_status yang wajib,
 * jadi kolom biodata lain dibuat NULLABLE dan diperiksa oleh aplikasi (App\Http\Requests\Employee\EmployeeRequest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('employment_status', 30);           // App\Enums\EmploymentStatus
            $table->string('nik', 20)->nullable()->unique();     // wajib untuk Karyawan Tetap & PKWT
            $table->string('registration_number', 20)->nullable()->unique(); // No. Registrasi (Register / Reg daftar upah)
            $table->string('name', 150);
            $table->char('ktp_number', 16)->nullable()->unique();
            $table->string('gender', 10)->nullable();            // App\Enums\Gender
            $table->string('birth_place', 100)->nullable();
            $table->date('birth_date')->nullable();
            $table->date('hire_date')->nullable();               // tanggal masuk perusahaan pertama
            $table->string('religion', 20)->nullable();          // App\Enums\Religion
            $table->string('marital_status', 20)->nullable();    // App\Enums\MaritalStatus
            $table->string('phone', 20)->nullable();
            $table->string('whatsapp', 20)->nullable();          // NULL = sama dengan telepon
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->foreignUuid('photo_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->string('active_status', 12)->default('Belum Aktif'); // App\Enums\EmployeeActiveStatus
            $table->date('inactive_since')->nullable();
            $table->timestamps();
        });

        // Akun login boleh dimiliki seorang karyawan (users.employee_id dibuat di migration users).
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });

        Schema::dropIfExists('employees');
    }
};
