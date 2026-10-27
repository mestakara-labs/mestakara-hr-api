<?php

namespace App\Http\Requests\Employee;

use App\Enums\DependentStatus;
use App\Enums\EducationLevel;
use App\Enums\EmploymentStatus;
use App\Enums\FamilyRelationship;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\Religion;
use App\Models\Employee;
use App\Support\UploadRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Isian form karyawan (tambah & ubah), dikirim sebagai multipart/form-data:
 *
 *   - biodata         employment_status, nik, registration_number, name, ktp_number, gender, ... (teks)
 *   - educations      daftar riwayat pendidikan (teks JSON)
 *   - family_members  daftar anggota batih (teks JSON)
 *   - photo           file foto baru (opsional); keep_photo = "1" kalau foto lama dipertahankan
 *   - certificate_file_<id pendidikan>  file ijazah baru per baris pendidikan (opsional)
 *   - save_mode       "draft" = cukup Nama + Status Karyawan (khusus karyawan Belum Aktif)
 *
 * Kesalahan di daftar pendidikan / batih dikumpulkan di isian "educations" / "family_members",
 * mis. ["Pendidikan ke-2 (S1): Tahun Lulus harus 4 digit tahun"] — sama dengan front end.
 */
class EmployeeRequest extends FormRequest
{
    /** Ubah teks JSON daftar pendidikan & batih menjadi array sebelum diperiksa. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'educations' => $this->decodeJsonList('educations'),
            'family_members' => $this->decodeJsonList('family_members'),
        ]);
    }

    /**
     * Disimpan sebagai draf? Hanya boleh untuk karyawan baru atau yang masih Belum Aktif.
     */
    public function isDraft(): bool
    {
        /** @var Employee|null $employee */
        $employee = $this->route('employee');

        return $this->input('save_mode') === 'draft' && ($employee === null || $employee->isPending());
    }

    public function rules(): array
    {
        $currentId = $this->route('employee')?->id;
        $status = EmploymentStatus::tryFrom((string) $this->input('employment_status'));

        $rules = [
            'employment_status' => [Rule::in(EmploymentStatus::values())],
            /** NIK karyawan. Wajib untuk Karyawan Tetap & PKWT. */
            'nik' => [
                Rule::requiredIf(! $this->isDraft() && $status?->requiresNik()),
                'nullable', 'string', 'max:20',
                Rule::unique('employees')->ignore($currentId),
            ],
            /** No. Registrasi karyawan (opsional), unik bila diisi. */
            'registration_number' => ['nullable', 'string', 'max:20', Rule::unique('employees')->ignore($currentId)],
            'name' => ['string', 'max:150'],
            /** No. KTP, 16 digit angka. */
            'ktp_number' => ['nullable', 'regex:/^\d{16}$/', Rule::unique('employees')->ignore($currentId)],
            'gender' => ['nullable', Rule::in(Gender::values())],
            'birth_place' => ['nullable', 'string', 'max:100'],
            /** Format YYYY-MM-DD. */
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            /** Tanggal masuk perusahaan pertama, format YYYY-MM-DD. Boleh di masa depan. */
            'hire_date' => ['nullable', 'date_format:Y-m-d'],
            'religion' => ['nullable', Rule::in(Religion::values())],
            'marital_status' => ['nullable', Rule::in(MaritalStatus::values())],
            'phone' => ['nullable', 'string', 'max:20'],
            /** Kosongkan ("") kalau sama dengan nomor telepon. */
            'whatsapp' => ['nullable', 'regex:/^\+?[\d\s-]{8,20}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string'],
            /** Foto JPG/PNG, maks. 2 MB. */
            'photo' => ['nullable', 'file'],
            'educations' => ['array'],
            'family_members' => ['array'],
        ];

        // Isian wajib: semua isian biodata, atau hanya Nama + Status Karyawan kalau draf.
        foreach (array_keys(Employee::REQUIRED_FIELDS) as $field) {
            $isRequired = ! $this->isDraft() || in_array($field, Employee::DRAFT_REQUIRED_FIELDS, true);
            array_unshift($rules[$field], $isRequired ? 'required' : 'nullable');
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = [
            'employment_status.in' => 'Status Karyawan tidak dikenal',
            'nik.required' => 'NIK Karyawan wajib diisi untuk '.$this->input('employment_status'),
            'nik.unique' => 'NIK "'.$this->input('nik').'" sudah dipakai karyawan lain',
            'registration_number.unique' => 'No. Registrasi "'.$this->input('registration_number').'" sudah dipakai karyawan lain',
            'ktp_number.regex' => 'No. KTP harus 16 digit angka',
            'ktp_number.unique' => 'No. KTP sudah dipakai karyawan lain',
            'gender.in' => 'Jenis Kelamin tidak dikenal',
            'religion.in' => 'Agama tidak dikenal',
            'marital_status.in' => 'Status Perkawinan tidak dikenal',
            'birth_date.before_or_equal' => 'Tanggal Lahir tidak boleh melewati hari ini',
            'email.email' => 'Format Email tidak valid',
            'whatsapp.regex' => 'Format No. WhatsApp tidak valid',
        ];

        foreach (Employee::REQUIRED_FIELDS as $field => $label) {
            $messages["{$field}.required"] = "{$label} wajib diisi";
        }

        return $messages;
    }

    /**
     * Pemeriksaan yang melibatkan beberapa isian, file, dan daftar pendidikan / batih.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $errors = $validator->errors();

                $birthDate = $this->input('birth_date');
                $hireDate = $this->input('hire_date');
                if ($birthDate && $hireDate && ! $errors->has('birth_date') && $hireDate <= $birthDate) {
                    $errors->add('hire_date', 'Tanggal Masuk harus setelah Tanggal Lahir');
                }

                $photo = $this->file('photo');
                if ($photo instanceof UploadedFile && $photoError = UploadRule::photo()->check($photo)) {
                    $errors->add('photo', $photoError);
                }

                foreach ($this->educationErrors() as $message) {
                    $errors->add('educations', $message);
                }

                foreach ($this->familyMemberErrors() as $message) {
                    $errors->add('family_members', $message);
                }
            },
        ];
    }

    /**
     * Kesalahan di daftar riwayat pendidikan, mis. "Pendidikan ke-2 (S1): Pilih Jenjang".
     * Selain draf, minimal harus ada satu riwayat pendidikan.
     *
     * @return list<string>
     */
    private function educationErrors(): array
    {
        $rows = $this->input('educations', []);
        $errors = [];

        if (! $this->isDraft() && $rows === []) {
            $errors[] = 'Isi minimal satu riwayat pendidikan';
        }

        foreach ($rows as $index => $row) {
            $level = (string) ($row['level'] ?? '');
            $startYear = (string) ($row['start_year'] ?? '');
            $graduationYear = (string) ($row['graduation_year'] ?? '');
            $label = 'Pendidikan ke-'.($index + 1).($level !== '' ? " ({$level})" : '');

            if (! in_array($level, EducationLevel::values(), true)) {
                $errors[] = "{$label}: Pilih Jenjang";
            }
            if (! $this->isYear($graduationYear)) {
                $errors[] = "{$label}: Tahun Lulus harus 4 digit tahun";
            }
            if ($startYear !== '' && ! $this->isYear($startYear)) {
                $errors[] = "{$label}: Tahun Masuk harus 4 digit tahun";
            }
            if ($this->isYear($startYear) && $this->isYear($graduationYear) && $startYear > $graduationYear) {
                $errors[] = "{$label}: Tahun Lulus tidak boleh lebih awal dari Tahun Masuk";
            }

            $certificate = $this->file('certificate_file_'.($row['id'] ?? ''));
            if ($certificate instanceof UploadedFile && $fileError = UploadRule::certificate()->check($certificate)) {
                $errors[] = "{$label}: {$fileError}";
            }
        }

        return $errors;
    }

    /**
     * Kesalahan di daftar anggota batih, mis. "Anggota batih ke-1 (Siti): Pilih Hubungan".
     *
     * @return list<string>
     */
    private function familyMemberErrors(): array
    {
        $errors = [];

        foreach ($this->input('family_members', []) as $index => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $birthDate = (string) ($row['birth_date'] ?? '');
            $ktpNumber = (string) ($row['ktp_number'] ?? '');
            $label = 'Anggota batih ke-'.($index + 1).($name !== '' ? " ({$name})" : '');

            if (! in_array($row['relationship'] ?? null, FamilyRelationship::values(), true)) {
                $errors[] = "{$label}: Pilih Hubungan";
            }
            if ($name === '') {
                $errors[] = "{$label}: Nama Lengkap wajib diisi";
            }
            if (! in_array($row['gender'] ?? null, Gender::values(), true)) {
                $errors[] = "{$label}: Pilih Jenis Kelamin";
            }
            if ($birthDate === '') {
                $errors[] = "{$label}: Pilih Tanggal Lahir";
            } elseif (! $this->isDate($birthDate)) {
                $errors[] = "{$label}: Tanggal Lahir tidak valid";
            } elseif ($birthDate > Carbon::today()->toDateString()) {
                $errors[] = "{$label}: Tanggal Lahir tidak boleh melewati hari ini";
            }
            if ($ktpNumber !== '' && ! preg_match('/^\d{16}$/', $ktpNumber)) {
                $errors[] = "{$label}: No. KTP harus 16 digit angka";
            }
            if (! in_array($row['dependent_status'] ?? null, DependentStatus::values(), true)) {
                $errors[] = "{$label}: Pilih Status Tanggungan";
            }
        }

        return $errors;
    }

    /** Baca teks JSON daftar dari form. Hasilnya [] kalau kosong atau bukan JSON yang benar. */
    private function decodeJsonList(string $key): array
    {
        $value = $this->input($key);

        if (is_array($value)) {
            return array_values($value);
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    private function isYear(string $value): bool
    {
        return preg_match('/^\d{4}$/', $value) === 1;
    }

    private function isDate(string $value): bool
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
