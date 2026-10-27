<?php

namespace Database\Seeders;

use App\Enums\ActivationType;
use App\Enums\DocumentScope;
use App\Enums\EmployeeActiveStatus;
use App\Enums\ProposalStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeeActivation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data awal karyawan Unit Agrowisata per September 2026 (data/employees.json), disusun dari:
 *
 *   - REKAP DATA KARYAWAN SEPTEMBER 2026      Tetap + PKWT, juga daftar & biodata casual
 *   - Lembur Tutup Buku Sept 2026 (+ DWrev)   biodata Tetap/PKWT (AMCO_Dik_202607), daftar lembur & absen casual
 *   - DU Casual Agro, Mal, Spe September 2026 daftar upah casual / daily worker
 *
 * Semua karyawan dibuat AKTIF lewat satu usulan Penambahan (Penerimaan Baru) yang BERLAKU,
 * dengan TMT = Tanggal Masuk, supaya status & masa kerjanya konsisten dengan riwayat
 * (lihat App\Services\EmployeeStatusService).
 *
 * Aman dijalankan berulang kali: karyawan dicari dari No. Registrasi, No. KTP, atau nama + status,
 * dan karyawan yang sudah punya riwayat penambahan tidak dibuatkan usulan lagi.
 */
class EmployeeSeeder extends Seeder
{
    public const DATA_FILE = __DIR__.'/data/employees.json';

    /** TMT aktif untuk karyawan yang Tanggal Masuknya tidak tercatat di Excel. */
    public const FALLBACK_EFFECTIVE_DATE = '2026-09-01';

    public function run(): void
    {
        /** @var list<array{employment_status: string, nik: ?string, registration_number: ?string, name: string, ktp_number: ?string, gender: ?string, birth_place: ?string, birth_date: ?string, hire_date: ?string, religion: ?string, marital_status: ?string, address: ?string}> $rows */
        $rows = json_decode(file_get_contents(self::DATA_FILE), true, flags: JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($rows) {
            $activation = null;

            foreach ($rows as $row) {
                $employee = $this->findExisting($row) ?? new Employee;
                $employee->fill($row);
                $employee->active_status = EmployeeActiveStatus::Active->value;
                $employee->save();

                if ($employee->activationItems()->exists()) {
                    continue;
                }

                $activation ??= EmployeeActivation::create([
                    'status' => ProposalStatus::Effective,
                    'document_scope' => DocumentScope::Collective,
                    'notes' => 'Data awal karyawan dari rekap Excel September 2026.',
                    'created_by' => $this->creatorId(),
                    'activated_at' => now(),
                ]);

                $activation->items()->create([
                    'employee_id' => $employee->id,
                    'type' => ActivationType::NewHire->value,
                    'effective_date' => $row['hire_date'] ?? self::FALLBACK_EFFECTIVE_DATE,
                ]);
            }
        });
    }

    /**
     * Karyawan yang sudah ada untuk baris ini: dari No. Registrasi, lalu No. KTP, lalu nama + status.
     *
     * @param  array<string, ?string>  $row
     */
    private function findExisting(array $row): ?Employee
    {
        if ($row['registration_number'] !== null) {
            return Employee::where('registration_number', $row['registration_number'])->first();
        }

        if ($row['ktp_number'] !== null) {
            return Employee::where('ktp_number', $row['ktp_number'])->first();
        }

        return Employee::where('name', $row['name'])
            ->where('employment_status', $row['employment_status'])
            ->whereNull('registration_number')
            ->first();
    }

    /** Pembuat usulan penambahan: akun Admin SDM pertama (dibuat UserSeeder). */
    private function creatorId(): string
    {
        return User::where('role', UserRole::AdminSdm)->orderBy('created_at')->value('id')
            ?? User::query()->orderBy('created_at')->valueOrFail('id');
    }
}
