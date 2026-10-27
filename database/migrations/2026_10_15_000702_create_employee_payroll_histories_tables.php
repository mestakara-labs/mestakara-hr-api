<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RIWAYAT data dasar upah per karyawan (ERD 3.6). Setiap catatan punya "berlaku mulai" (effective_date);
 * nilai yang berlaku pada tanggal X = catatan dengan effective_date ≤ X yang paling akhir
 * (lihat App\Support\EffectiveRecords). Catatan TIDAK PERNAH diubah/dihapus: koreksi = catatan baru.
 *
 *   employee_bank_accounts   rekening bank (semua karyawan)
 *   employee_cost_centers    cost center (semua karyawan)
 *   employee_wage_schemes    skema upah (HANYA Karyawan Casual & Daily Worker)
 *
 * Nominal rupiah disimpan sebagai bilangan bulat (tanpa desimal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('bank_name', 100);
            $table->string('account_number', 30);                       // teks berisi angka saja
            $table->string('account_holder', 150);
            $table->date('effective_date');
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'effective_date']);
        });

        Schema::create('employee_cost_centers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('cost_center_id')->constrained('cost_centers')->restrictOnDelete();
            $table->date('effective_date');
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'effective_date']);
        });

        Schema::create('employee_wage_schemes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('wage_type', 20);                            // App\Enums\WageType
            $table->unsignedInteger('base_salary');                     // Gaji Pokok
            $table->unsignedTinyInteger('working_days')->default(25);   // HKE
            $table->boolean('is_capped')->default(false);               // Dibatasi 20 hari
            $table->unsignedTinyInteger('cap_days')->nullable();        // NULL kalau tidak dibatasi
            $table->unsignedInteger('operational_position_allowance')->default(0);
            $table->unsignedInteger('incentive')->default(0);
            $table->boolean('has_meal_allowance')->default(false);
            $table->unsignedInteger('meal_allowance_per_day')->default(0); // 0 kalau tidak diberi uang makan
            $table->foreignUuid('payroll_group_id')->nullable()->constrained('payroll_groups')->restrictOnDelete();
            $table->date('effective_date');
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_wage_schemes');
        Schema::dropIfExists('employee_cost_centers');
        Schema::dropIfExists('employee_bank_accounts');
    }
};
