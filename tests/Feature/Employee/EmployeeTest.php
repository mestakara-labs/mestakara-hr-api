<?php

namespace Tests\Feature\Employee;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengujian Data Karyawan (/employees): multipart, pendidikan, batih, dan file.
 */
class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
        Storage::fake('local');
    }

    /** Isian form lengkap, bentuknya sama dengan toFormData() di front end. */
    private function fullForm(array $override = []): array
    {
        return array_merge([
            'employment_status' => 'Karyawan Tetap',
            'nik' => '20230001',
            'name' => 'Budi Santoso',
            'ktp_number' => '3201010101010001',
            'gender' => 'Laki-laki',
            'birth_place' => 'Bogor',
            'birth_date' => '1990-05-01',
            'hire_date' => '2015-01-02',
            'religion' => 'Islam',
            'marital_status' => 'Kawin',
            'phone' => '081234567890',
            'whatsapp' => '',
            'email' => 'budi@contoh.test',
            'address' => '',
            'save_mode' => 'full',
            'educations' => json_encode([
                ['id' => 'baru-1', 'level' => 'SMA/SMK', 'graduation_year' => '2008', 'keep_certificate_file' => false],
                ['id' => 'baru-2', 'level' => 'S1', 'major' => 'Akuntansi', 'start_year' => '2008', 'graduation_year' => '2012', 'keep_certificate_file' => false],
            ]),
            'family_members' => json_encode([
                ['id' => 'baru-x', 'relationship' => 'Istri', 'name' => 'Siti', 'gender' => 'Perempuan', 'birth_date' => '1992-02-02', 'dependent_status' => 'Ditanggung'],
            ]),
        ], $override);
    }

    public function test_save_draft_with_only_name_and_employment_status(): void
    {
        $this->postJson('/api/v1/employees', [
            'name' => 'Budi', 'employment_status' => 'Karyawan Tetap', 'save_mode' => 'draft',
            'educations' => '[]', 'family_members' => '[]',
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Draf karyawan berhasil disimpan.')
            ->assertJsonPath('data.active_status', 'Belum Aktif')
            ->assertJsonPath('data.education', '-')
            ->assertJsonPath('data.missing_fields.0', 'No. KTP');
    }

    public function test_create_full_employee_with_photo_and_certificate(): void
    {
        $response = $this->post('/api/v1/employees', $this->fullForm([
            'photo' => UploadedFile::fake()->image('foto.png'),
            'certificate_file_baru-2' => UploadedFile::fake()->create('ijazah.pdf', 100, 'application/pdf'),
        ]), ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.missing_fields', [])
            ->assertJsonPath('data.education', 'S1')
            ->assertJsonPath('data.major', 'Akuntansi')
            ->assertJsonPath('data.educations.0.level', 'S1')
            ->assertJsonPath('data.educations.0.certificate_file.name', 'ijazah.pdf')
            ->assertJsonPath('data.educations.1.certificate_file', null)
            ->assertJsonPath('data.family_members.0.name', 'Siti');

        $employee = Employee::first();
        $this->assertNotNull($employee->photo_file_id);
        $this->assertStringStartsWith("/api/employees/{$employee->id}/files?type=foto", $response->json('data.photo_url'));
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_full_save_validates_fields_and_lists_like_front_end(): void
    {
        $this->post('/api/v1/employees', $this->fullForm([
            'employment_status' => 'Karyawan PKWT',
            'nik' => '',
            'religion' => '',
            'ktp_number' => '123',
            'hire_date' => '1980-01-01',
            'educations' => json_encode([['id' => 'baru-1', 'level' => '', 'graduation_year' => '20']]),
            'family_members' => json_encode([['id' => 'baru-x', 'name' => '']]),
        ]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.nik.0', 'NIK Karyawan wajib diisi untuk Karyawan PKWT')
            ->assertJsonPath('errors.religion.0', 'Agama wajib diisi')
            ->assertJsonPath('errors.ktp_number.0', 'No. KTP harus 16 digit angka')
            ->assertJsonPath('errors.hire_date.0', 'Tanggal Masuk harus setelah Tanggal Lahir')
            ->assertJsonPath('errors.educations.0', 'Pendidikan ke-1: Pilih Jenjang')
            ->assertJsonPath('errors.family_members.0', 'Anggota batih ke-1: Pilih Hubungan');
    }

    public function test_full_save_requires_at_least_one_education(): void
    {
        $this->post('/api/v1/employees', $this->fullForm(['educations' => '[]']), ['Accept' => 'application/json'])
            ->assertJsonPath('errors.educations.0', 'Isi minimal satu riwayat pendidikan');
    }

    public function test_nik_must_be_unique(): void
    {
        Employee::factory()->create(['nik' => '20230001']);

        $this->post('/api/v1/employees', $this->fullForm(), ['Accept' => 'application/json'])
            ->assertJsonPath('errors.nik.0', 'NIK "20230001" sudah dipakai karyawan lain');
    }

    public function test_registration_number_is_saved_and_must_be_unique(): void
    {
        $this->post('/api/v1/employees', $this->fullForm(['registration_number' => '01070577040088']), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.registration_number', '01070577040088');

        $this->post('/api/v1/employees', $this->fullForm([
            'nik' => '20230002',
            'ktp_number' => '3201010101010002',
            'registration_number' => '01070577040088',
        ]), ['Accept' => 'application/json'])
            ->assertJsonPath('errors.registration_number.0', 'No. Registrasi "01070577040088" sudah dipakai karyawan lain');
    }

    public function test_update_keeps_or_removes_photo_and_certificate(): void
    {
        $id = $this->post('/api/v1/employees', $this->fullForm([
            'photo' => UploadedFile::fake()->image('foto.png'),
            'certificate_file_baru-2' => UploadedFile::fake()->create('ijazah.pdf', 100, 'application/pdf'),
        ]), ['Accept' => 'application/json'])->json('data.id');

        $s1 = Employee::find($id)->educations()->where('level', 'S1')->first();

        // Simpan ulang: foto dipertahankan, ijazah S1 dipertahankan, baris SMA dihapus.
        $this->put("/api/v1/employees/{$id}", $this->fullForm([
            'keep_photo' => '1',
            'educations' => json_encode([
                ['id' => $s1->id, 'level' => 'S1', 'graduation_year' => '2012', 'keep_certificate_file' => true],
            ]),
        ]), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message', 'Data karyawan berhasil diperbarui.')
            ->assertJsonCount(1, 'data.educations')
            ->assertJsonPath('data.educations.0.id', $s1->id)
            ->assertJsonPath('data.educations.0.certificate_file.name', 'ijazah.pdf');
        $this->assertNotNull(Employee::find($id)->photo_file_id);

        // Simpan lagi tanpa keep_photo & tanpa keep_certificate_file: keduanya dihapus.
        $this->put("/api/v1/employees/{$id}", $this->fullForm([
            'educations' => json_encode([
                ['id' => $s1->id, 'level' => 'S1', 'graduation_year' => '2012', 'keep_certificate_file' => false],
            ]),
        ]), ['Accept' => 'application/json'])
            ->assertJsonPath('data.photo_url', null)
            ->assertJsonPath('data.educations.0.certificate_file', null);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_files_endpoint_returns_photo_and_404_for_missing_file(): void
    {
        $id = $this->post('/api/v1/employees', $this->fullForm([
            'photo' => UploadedFile::fake()->image('foto.png'),
        ]), ['Accept' => 'application/json'])->json('data.id');

        $this->get("/api/v1/employees/{$id}/files?type=foto")->assertOk();
        $this->getJson("/api/v1/employees/{$id}/files?type=ijazah&education=tidak-ada")
            ->assertNotFound()
            ->assertJsonPath('message', 'File tidak ditemukan.');
    }

    public function test_only_pending_employee_can_be_deleted(): void
    {
        $draft = Employee::factory()->create();
        $active = Employee::factory()->active()->create();

        $this->deleteJson("/api/v1/employees/{$draft->id}")->assertOk();
        $this->deleteJson("/api/v1/employees/{$active->id}")->assertConflict();

        $this->assertModelMissing($draft);
        $this->assertModelExists($active);
    }

    public function test_active_employee_cannot_be_saved_as_draft(): void
    {
        $active = Employee::factory()->active()->create();

        $this->put("/api/v1/employees/{$active->id}", [
            'name' => 'Budi', 'employment_status' => 'Karyawan Tetap', 'save_mode' => 'draft',
            'educations' => '[]', 'family_members' => '[]',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.ktp_number.0', 'No. KTP wajib diisi');
    }
}
