<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\LegacyAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Jabatan, objek/unit, & kedudukan ASLI tiap karyawan (data/legacy_assignments.json), dari sheet
 * "Database Karyawan" (Database Karyawan Unit Agrowisata - Oktober 2026). Hanya referensi.
 *
 * Karyawan dicari dari No. Registrasi, lalu NIK, lalu nama. Kode afdeling diisi dari kedudukannya
 * memakai NomenclatureAliasSeeder::SUB_UNITS (kosong kalau kedudukannya belum punya sub unit).
 *
 * Satu data terakhir per karyawan: menjalankan ulang menimpa data lama karyawan yang sama.
 * Butuh EmployeeSeeder.
 */
class LegacyAssignmentSeeder extends Seeder
{
    public const DATA_FILE = __DIR__.'/data/legacy_assignments.json';

    public const SOURCE = 'Database Karyawan - Oktober 2026';

    public function run(): void
    {
        /** @var list<array{registration_number: ?string, nik: ?string, name: string, raw_position: ?string, raw_placement: ?string, raw_sub_unit: ?string}> $rows */
        $rows = json_decode(file_get_contents(self::DATA_FILE), true, flags: JSON_THROW_ON_ERROR);

        $employees = Employee::all(['id', 'registration_number', 'nik', 'name']);
        $byRegistration = $employees->whereNotNull('registration_number')->keyBy('registration_number');
        $byNik = $employees->whereNotNull('nik')->keyBy('nik');
        $byName = $employees->keyBy(fn (Employee $employee) => mb_strtoupper($employee->name));

        DB::transaction(function () use ($rows, $byRegistration, $byNik, $byName) {
            foreach ($rows as $row) {
                $employee = $byRegistration->get($row['registration_number'])
                    ?? $byNik->get($row['nik'])
                    ?? $byName->get(mb_strtoupper($row['name']));

                if ($employee === null) {
                    $this->command?->warn("Karyawan tidak ditemukan, dilewati: {$row['name']}");

                    continue;
                }

                LegacyAssignment::updateOrCreate(['employee_id' => $employee->id], [
                    'raw_position' => $row['raw_position'],
                    'raw_placement' => $row['raw_placement'],
                    'raw_sub_unit' => $row['raw_sub_unit'],
                    'raw_afdeling_code' => NomenclatureAliasSeeder::SUB_UNITS[$row['raw_sub_unit'] ?? ''] ?? null,
                    'source' => self::SOURCE,
                ]);
            }
        });
    }
}
