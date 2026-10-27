<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use App\Enums\EducationLevel;
use App\Enums\EmployeeActiveStatus;
use App\Enums\EmploymentStatus;
use App\Support\EffectiveRecords;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Karyawan (tabel employees): biodata + riwayat pendidikan + data batih.
 *
 * Nilai seperti pendidikan terakhir dan isian yang belum lengkap DIHITUNG di sini, tidak disimpan.
 */
#[Fillable([
    'employment_status', 'nik', 'registration_number', 'name', 'ktp_number', 'gender', 'birth_place', 'birth_date', 'hire_date',
    'religion', 'marital_status', 'phone', 'whatsapp', 'email', 'address', 'photo_file_id',
    'active_status', 'inactive_since',
])]
class Employee extends Model
{
    use HasFactory, HasUuids;

    /**
     * Isian biodata yang WAJIB untuk karyawan aktif: [nama isian => label].
     * Draf karyawan (Belum Aktif) cukup DRAFT_REQUIRED_FIELDS; sisanya dilengkapi sebelum diaktifkan.
     */
    public const REQUIRED_FIELDS = [
        'name' => 'Nama Lengkap',
        'employment_status' => 'Status Karyawan',
        'ktp_number' => 'No. KTP',
        'gender' => 'Jenis Kelamin',
        'birth_place' => 'Tempat Lahir',
        'birth_date' => 'Tanggal Lahir',
        'hire_date' => 'Tanggal Masuk',
        'religion' => 'Agama',
        'marital_status' => 'Status Perkawinan',
        'phone' => 'No. Telepon',
    ];

    /** Isian wajib saat disimpan sebagai draf. */
    public const DRAFT_REQUIRED_FIELDS = ['name', 'employment_status'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'hire_date' => 'date',
            'inactive_since' => 'date',
        ];
    }

    public function educations(): HasMany
    {
        return $this->hasMany(EmployeeEducation::class);
    }

    public function familyMembers(): HasMany
    {
        return $this->hasMany(EmployeeFamilyMember::class)->orderBy('id');
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(File::class, 'photo_file_id');
    }

    /** Keikutsertaan di usulan Penambahan (dasar riwayat status: menjadi Aktif). */
    public function activationItems(): HasMany
    {
        return $this->hasMany(EmployeeActivationItem::class);
    }

    /** Keikutsertaan di usulan Penonaktifan (dasar riwayat status: menjadi Nonaktif). */
    public function terminationItems(): HasMany
    {
        return $this->hasMany(EmployeeTerminationItem::class);
    }

    /** Jabatan, objek/unit, & kedudukan ASLI dari data lama (referensi, belum terstandarisasi). */
    public function legacyAssignment(): HasOne
    {
        return $this->hasOne(LegacyAssignment::class);
    }

    /** Akun login milik karyawan ini (kalau ada). */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /** Semua penugasan jabatan (aktif maupun riwayat). */
    public function assignments(): HasMany
    {
        return $this->hasMany(PositionAssignment::class);
    }

    /** Penugasan jabatan yang sedang berjalan hari ini. */
    public function activeAssignments(): HasMany
    {
        return $this->assignments()->active();
    }

    /**
     * Penugasan yang BERJALAN pada tanggal tertentu: TMT mulai ≤ tanggal, TMT selesai kosong atau ≥ tanggal.
     * Tanpa tanggal = hari ini (relasi activeAssignments). Dengan tanggal butuh relasi assignments.
     *
     * @return Collection<int, PositionAssignment>
     */
    public function assignmentsOn(?Carbon $date = null): Collection
    {
        if ($date === null) {
            return $this->activeAssignments;
        }

        return $this->assignments
            ->filter(fn (PositionAssignment $assignment) => $assignment->start_date->lessThanOrEqualTo($date)
                && ($assignment->end_date === null || $assignment->end_date->greaterThanOrEqualTo($date)))
            ->values();
    }

    /**
     * Penugasan UTAMA (dasar golongan karpim/karpel & usia pensiun): penugasan Definitif yang berjalan,
     * atau kalau tidak ada, penugasan berjalan dengan level BRM tertinggi. null = belum ditempatkan.
     * Tanpa tanggal = hari ini (butuh relasi activeAssignments.position);
     * dengan tanggal = pada tanggal itu (butuh relasi assignments.position).
     */
    public function mainAssignment(?Carbon $date = null): ?PositionAssignment
    {
        return $this->assignmentsOn($date)
            ->sortBy(fn (PositionAssignment $assignment) => [
                $assignment->assignment_status === AssignmentStatus::Definitive->value ? 0 : 1,
                $assignment->position->brm_level,
            ])
            ->first();
    }

    // -------------------------------------------------------------------------
    // Data dasar upah (riwayat ber-"berlaku mulai", lihat App\Support\EffectiveRecords)
    // -------------------------------------------------------------------------

    /** Riwayat rekening bank. */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(EmployeeBankAccount::class);
    }

    /** Riwayat cost center. */
    public function costCenterHistories(): HasMany
    {
        return $this->hasMany(EmployeeCostCenter::class);
    }

    /** Riwayat skema upah (hanya Karyawan Casual & Daily Worker). */
    public function wageSchemes(): HasMany
    {
        return $this->hasMany(EmployeeWageScheme::class);
    }

    /** Rekening bank yang berlaku pada tanggal itu, atau null. Butuh relasi bankAccounts. */
    public function bankAccountOn(Carbon $date): ?EmployeeBankAccount
    {
        return EffectiveRecords::on($this->bankAccounts, $date);
    }

    /** Cost center yang berlaku pada tanggal itu, atau null. Butuh relasi costCenterHistories.costCenter. */
    public function costCenterOn(Carbon $date): ?EmployeeCostCenter
    {
        return EffectiveRecords::on($this->costCenterHistories, $date);
    }

    /** Skema upah yang berlaku pada tanggal itu, atau null (= "Belum diatur"). Butuh relasi wageSchemes. */
    public function wageSchemeOn(Carbon $date): ?EmployeeWageScheme
    {
        return EffectiveRecords::on($this->wageSchemes, $date);
    }

    /** Karyawan Casual / Daily Worker (memakai skema upah)? */
    public function hasDailyWage(): bool
    {
        return EmploymentStatus::tryFrom($this->employment_status)?->hasDailyWage() ?? false;
    }

    /** Alamat foto lewat front end, mis. "/api/employees/{id}/files?type=foto&v=...". null = tanpa foto. */
    public function photoUrl(): ?string
    {
        if ($this->photo_file_id === null) {
            return null;
        }

        return config('hr.file_url_prefix')."/employees/{$this->id}/files?type=foto&v={$this->photo_file_id}";
    }

    /** Karyawan masih Belum Aktif (draf)? Hanya draf yang boleh disimpan setengah jadi dan dihapus. */
    public function isPending(): bool
    {
        return $this->active_status === EmployeeActiveStatus::Pending->value;
    }

    /** Riwayat pendidikan urut dari jenjang TERTINGGI (S3 … SD). */
    public function educationsFromHighest(): array
    {
        return $this->educations
            ->sortByDesc(fn (EmployeeEducation $education) => EducationLevel::rank($education->level))
            ->values()
            ->all();
    }

    /** Pendidikan terakhir = jenjang tertinggi, atau null kalau riwayatnya kosong. */
    public function highestEducation(): ?EmployeeEducation
    {
        return $this->educationsFromHighest()[0] ?? null;
    }

    /**
     * Label isian wajib yang masih kosong, mis. ["No. KTP", "Agama"]. Kosong = data Lengkap.
     * NIK Karyawan ikut wajib untuk Karyawan Tetap & PKWT, juga minimal satu riwayat pendidikan.
     *
     * @return list<string>
     */
    public function missingFields(): array
    {
        $missing = [];

        foreach (self::REQUIRED_FIELDS as $field => $label) {
            if (blank($this->{$field})) {
                $missing[] = $label;
            }
        }

        if (blank($this->nik) && EmploymentStatus::tryFrom($this->employment_status)?->requiresNik()) {
            $missing[] = 'NIK Karyawan';
        }

        if ($this->educations->isEmpty()) {
            $missing[] = 'Riwayat Pendidikan';
        }

        return $missing;
    }
}
