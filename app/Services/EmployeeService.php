<?php

namespace App\Services;

use App\Http\Requests\Employee\EmployeeRequest;
use App\Models\Employee;
use App\Models\EmployeeEducation;
use App\Models\EmployeeFamilyMember;
use Illuminate\Support\Facades\DB;

/**
 * Menyimpan data karyawan dari form (tambah & ubah) dalam SATU transaksi:
 *
 *   1. biodata
 *   2. foto            (file baru / pertahankan yang lama / hapus)
 *   3. riwayat pendidikan + file ijazah per baris
 *   4. data batih
 *
 * Baris pendidikan / batih yang tidak dikirim lagi dianggap dihapus.
 * Baris baru dari front end ber-id "baru-..." dan mendapat UUID baru di sini.
 */
class EmployeeService
{
    public function __construct(private FileStorage $fileStorage) {}

    /** Simpan karyawan (baru: kirim `new Employee`). Hasilnya karyawan yang sudah tersimpan. */
    public function save(Employee $employee, EmployeeRequest $request): Employee
    {
        return DB::transaction(function () use ($employee, $request) {
            $employee->fill($request->safe()->only(array_keys(Employee::REQUIRED_FIELDS)));
            $employee->fill($request->safe()->only(['nik', 'registration_number', 'whatsapp', 'email', 'address']));
            $this->savePhoto($employee, $request);
            $employee->save();

            $this->saveEducations($employee, $request);
            $this->saveFamilyMembers($employee, $request->validated('family_members'));

            return $employee->refresh();
        });
    }

    /** Hapus karyawan beserta semua filenya (foto & ijazah). */
    public function delete(Employee $employee): void
    {
        DB::transaction(function () use ($employee) {
            foreach ($employee->educations as $education) {
                $this->fileStorage->delete($education->certificateFile);
            }

            $photo = $employee->photo;
            $employee->delete();               // pendidikan & batih ikut terhapus (cascade)
            $this->fileStorage->delete($photo);
        });
    }

    /** Foto: file baru menggantikan yang lama; tanpa file baru & tanpa keep_photo = foto dihapus. */
    private function savePhoto(Employee $employee, EmployeeRequest $request): void
    {
        if ($request->hasFile('photo')) {
            $oldPhoto = $employee->photo;
            $employee->photo_file_id = $this->fileStorage->store($request->file('photo'), 'foto-karyawan')->id;
            $this->fileStorage->delete($oldPhoto);

            return;
        }

        if ($request->input('keep_photo') !== '1') {
            $oldPhoto = $employee->photo;
            $employee->photo_file_id = null;
            $this->fileStorage->delete($oldPhoto);
        }
    }

    /** Riwayat pendidikan: ubah baris lama, tambah baris baru, hapus baris yang tidak dikirim lagi. */
    private function saveEducations(Employee $employee, EmployeeRequest $request): void
    {
        $existing = $employee->educations()->get()->keyBy('id');
        $keptIds = [];

        foreach ($request->validated('educations') as $row) {
            $sentId = (string) ($row['id'] ?? '');
            $education = $existing->get($sentId) ?? new EmployeeEducation(['employee_id' => $employee->id]);

            $education->fill([
                'level' => $row['level'],
                'institution' => $this->textOrNull($row['institution'] ?? null),
                'major' => $this->textOrNull($row['major'] ?? null),
                'city' => $this->textOrNull($row['city'] ?? null),
                'start_year' => $this->textOrNull($row['start_year'] ?? null),
                'graduation_year' => $row['graduation_year'],
                'gpa' => $this->textOrNull($row['gpa'] ?? null),
                'title_front' => $this->textOrNull($row['title_front'] ?? null),
                'title_back' => $this->textOrNull($row['title_back'] ?? null),
                'certificate_number' => $this->textOrNull($row['certificate_number'] ?? null),
            ]);

            // File ijazah: file baru dikirim sebagai "certificate_file_<id yang dikirim>".
            $oldCertificate = $education->certificateFile;
            $newCertificate = $request->file("certificate_file_{$sentId}");

            if ($newCertificate) {
                $education->certificate_file_id = $this->fileStorage->store($newCertificate, 'ijazah')->id;
                $this->fileStorage->delete($oldCertificate);
            } elseif (! filter_var($row['keep_certificate_file'] ?? false, FILTER_VALIDATE_BOOL)) {
                $education->certificate_file_id = null;
                $this->fileStorage->delete($oldCertificate);
            }

            $education->save();
            $keptIds[] = $education->id;
        }

        foreach ($existing->except($keptIds) as $removed) {
            $certificate = $removed->certificateFile;
            $removed->delete();
            $this->fileStorage->delete($certificate);
        }
    }

    /** Data batih: ubah baris lama, tambah baris baru, hapus baris yang tidak dikirim lagi. */
    private function saveFamilyMembers(Employee $employee, array $rows): void
    {
        $existing = $employee->familyMembers()->get()->keyBy('id');
        $keptIds = [];

        foreach ($rows as $row) {
            $member = $existing->get((string) ($row['id'] ?? '')) ?? new EmployeeFamilyMember(['employee_id' => $employee->id]);

            $member->fill([
                'relationship' => $row['relationship'],
                'name' => trim($row['name']),
                'ktp_number' => $this->textOrNull($row['ktp_number'] ?? null),
                'gender' => $row['gender'],
                'birth_place' => $this->textOrNull($row['birth_place'] ?? null),
                'birth_date' => $row['birth_date'],
                'occupation' => $this->textOrNull($row['occupation'] ?? null),
                'dependent_status' => $row['dependent_status'],
            ])->save();

            $keptIds[] = $member->id;
        }

        $employee->familyMembers()->whereKeyNot($keptIds)->delete();
    }

    /** Teks kosong -> null; selain itu spasi di awal/akhir dibuang. */
    private function textOrNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
