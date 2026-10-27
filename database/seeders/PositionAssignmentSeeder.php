<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\PlacementObject;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\SubUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data awal PENEMPATAN (penugasan jabatan) SEMUA karyawan per Oktober 2026 (data/position_assignments.json),
 * dari sheet "Database Karyawan Standar" (Database Karyawan Unit Agrowisata - Oktober 2026):
 *
 *   jabatan   = kolom Posisi/Jabatan Standarisasi
 *   sub unit  = kolom Kedudukan (Bandung, Subang, Sukabumi -> Gunung Mas, karena belum ada sub unitnya);
 *               DZULFI AGIRA PRATAMA -> Afdeling Gunung Mas (praktiknya menjabat asisten tanaman)
 *   objek     = kolom Objek Penempatan (kosong kalau "Tidak Disebutkan")
 *   TMT       = TMT penempatan sebelumnya kalau jabatannya sama, selain itu Tanggal Masuk (kosong: 1 Okt 2026)
 *   atasan    = belum diisi
 *
 * Karyawan dicari dari No. Registrasi, lalu NIK, lalu nama.
 * Aman dijalankan berulang kali: penugasan data awal ditandai catatan NOTES, jadi yang diperbarui hanya
 * penugasan itu (penugasan buatan aplikasi tidak disentuh). Butuh OrganizationSeeder, SubUnitSeeder,
 * PlacementObjectSeeder, dan EmployeeSeeder.
 */
class PositionAssignmentSeeder extends Seeder
{
    public const DATA_FILE = __DIR__.'/data/position_assignments.json';

    /** Catatan penanda penugasan data awal. */
    public const NOTES = 'Data awal penempatan dari Database Karyawan Standar - Oktober 2026.';

    public function run(): void
    {
        /** @var list<array{registration_number: ?string, nik: ?string, name: string, position_code: string, sub_unit: string, placement_objects: list<string>, assignment_status: string, start_date: string, decree_type: ?string, decree_number: ?string, decree_date: ?string}> $rows */
        $rows = json_decode(file_get_contents(self::DATA_FILE), true, flags: JSON_THROW_ON_ERROR);

        $employees = Employee::all(['id', 'registration_number', 'nik', 'name']);
        $byRegistration = $employees->whereNotNull('registration_number')->keyBy('registration_number');
        $byNik = $employees->whereNotNull('nik')->keyBy('nik');
        $byName = $employees->keyBy(fn (Employee $employee) => mb_strtoupper($employee->name));
        $positionIds = Position::pluck('id', 'code');
        $subUnitIds = SubUnit::pluck('id', 'afdeling_code');
        $objectIds = PlacementObject::pluck('id', 'name');

        DB::transaction(function () use ($rows, $byRegistration, $byNik, $byName, $positionIds, $subUnitIds, $objectIds) {
            foreach ($rows as $row) {
                $employee = $byRegistration->get($row['registration_number'])
                    ?? $byNik->get($row['nik'])
                    ?? $byName->get(mb_strtoupper($row['name']));

                if ($employee === null) {
                    $this->command?->warn("Karyawan tidak ditemukan, dilewati: {$row['name']}");

                    continue;
                }

                $assignment = PositionAssignment::updateOrCreate(
                    ['employee_id' => $employee->id, 'notes' => self::NOTES],
                    [
                        'position_id' => $positionIds[$row['position_code']],
                        'sub_unit_id' => $subUnitIds[$row['sub_unit']],
                        'assignment_status' => $row['assignment_status'],
                        'start_date' => $row['start_date'],
                        'decree_type' => $row['decree_type'],
                        'decree_number' => $row['decree_number'],
                        'decree_date' => $row['decree_date'],
                    ],
                );

                $assignment->placementObjects()->sync(
                    array_map(fn (string $name) => $objectIds[$name], $row['placement_objects']),
                );
            }
        });
    }
}
