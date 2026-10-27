<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DAFTAR UPAH Casual & Daily Worker (ERD 3.8):
 *
 *   payroll_deductions          potongan manual per karyawan per periode (BPJS TK/KES, PPIP/PPh 21, Kebun/Unit)
 *   payroll_deduction_changes   jejak perubahan potongan: satu baris per isian yang berubah
 *   payroll_period_events       riwayat kunci / buka kunci periode (status periode tetap di payroll_periods)
 *   payroll_snapshot_rows       POTRET angka daftar upah per karyawan saat periode dikunci
 *   payroll_snapshot_signers    POTRET penanda tangan yang sudah di-resolve (nama, jabatan, tempat) saat dikunci
 *
 * Selama periode dikunci, daftar upah & ekspor memakai potret, bukan dihitung ulang. Buka kunci menghapus potret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_deductions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('period', 7);                                  // YYYY-MM
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->unsignedInteger('bpjs')->default(0);                // BPJS TK/KES
            $table->unsignedInteger('pph21')->default(0);               // PPIP/PPh Ps.21
            $table->unsignedInteger('unit')->default(0);                // Kebun / Unit
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['period', 'employee_id']);
        });

        Schema::create('payroll_deduction_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('period', 7);
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('field', 10);                                // bpjs / pph21 / unit
            $table->unsignedInteger('old_value');
            $table->unsignedInteger('new_value');
            $table->string('source', 10);                               // ubah / salin
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['period', 'employee_id']);
        });

        Schema::create('payroll_period_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('period', 7);
            $table->string('action', 10);                               // App\Enums\PayrollLockAction: kunci / buka
            $table->text('reason')->nullable();                         // wajib untuk buka kunci
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('period');
        });

        Schema::create('payroll_snapshot_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('period', 7);
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('registration_number', 20)->nullable();
            $table->string('name', 150);
            $table->string('employment_status', 30);
            $table->string('position', 150)->nullable();
            $table->string('sub_unit_name', 150)->nullable();
            $table->string('afdeling_code', 20)->nullable();
            $table->uuid('payroll_group_id')->nullable();               // tanpa FK: potret tetap walau master berubah
            $table->string('payroll_group_name', 100)->nullable();
            $table->uuid('division_id')->nullable();
            $table->string('division_name', 150)->nullable();
            $table->uuid('cost_center_id')->nullable();
            $table->string('cost_center_code', 20)->nullable();
            $table->string('cost_center_name', 150)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->string('account_number', 30)->nullable();
            $table->string('account_name', 150)->nullable();
            $table->string('wage_type', 20)->nullable();
            $table->unsignedTinyInteger('hke')->default(0);
            $table->unsignedTinyInteger('present_days')->default(0);
            $table->unsignedTinyInteger('active_days')->default(0);
            $table->unsignedTinyInteger('calendar_days')->default(0);
            $table->unsignedInteger('base_salary')->default(0);
            $table->unsignedInteger('daily_rate')->default(0);
            $table->unsignedTinyInteger('cap_days')->nullable();
            $table->unsignedInteger('base_pay')->default(0);
            $table->unsignedInteger('regular_overtime_pay')->default(0);
            $table->unsignedInteger('dw_overtime_pay')->default(0);
            $table->unsignedInteger('overtime_pay')->default(0);
            $table->unsignedInteger('operational_allowance')->default(0);
            $table->unsignedInteger('incentive')->default(0);
            $table->unsignedInteger('meal_allowance')->default(0);
            $table->unsignedInteger('gross')->default(0);
            $table->unsignedInteger('bpjs')->default(0);
            $table->unsignedInteger('pph21')->default(0);
            $table->unsignedInteger('unit')->default(0);
            $table->unsignedInteger('deductions_total')->default(0);
            $table->integer('net')->default(0);                         // bisa negatif kalau upah turun setelah potongan diisi
            $table->text('warnings')->nullable();                       // satu peringatan per baris
            $table->string('dw_status', 20)->nullable();                // status lembur DW (DwPay::STATUS_*), null = tanpa DW
            $table->timestamp('created_at')->nullable();

            $table->unique(['period', 'employee_id']);
        });

        Schema::create('payroll_snapshot_signers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('period', 7);
            $table->string('owner_type', 20);                           // App\Enums\SignatureOwnerType
            $table->uuid('owner_id')->nullable();                       // NULL = Umum
            $table->string('place', 100);
            $table->string('document', 20);                             // App\Enums\SignatureDocument
            $table->boolean('inherited')->default(false);               // true = memakai susunan Umum
            $table->unsignedSmallInteger('section_order');
            $table->string('section_label', 100);
            $table->unsignedSmallInteger('signer_order');
            $table->string('name', 150);
            $table->string('position', 150);

            $table->index(['period', 'owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_snapshot_signers');
        Schema::dropIfExists('payroll_snapshot_rows');
        Schema::dropIfExists('payroll_period_events');
        Schema::dropIfExists('payroll_deduction_changes');
        Schema::dropIfExists('payroll_deductions');
    }
};
