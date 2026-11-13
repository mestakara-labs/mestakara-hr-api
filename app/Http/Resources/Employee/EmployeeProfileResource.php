<?php

namespace App\Http\Resources\Employee;

use App\Http\Resources\Payroll\EmployeeBankAccountResource;
use App\Http\Resources\Payroll\EmployeeCostCenterResource;
use App\Models\Employee;
use App\Models\EmployeeEducation;
use App\Models\EmployeeFamilyMember;
use App\Models\PositionAssignment;
use App\Services\EmployeeStatusService;
use App\Services\FileStorage;
use App\Support\DataPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Data karyawan, sesuai EmployeeProfileResource di front end (types/api/employee.ts).
 * Controller wajib memuat relasi di RELATIONS (atau RELATIONS_FOR_PERIOD kalau memakai asOf()).
 *
 * Status kepegawaian, masa kerja, dan jabatan utama dihitung per HARI INI,
 * atau per TANGGAL ACUAN periode kalau diberi ->asOf($tanggal) (filter "Periode Data").
 *
 * @mixin Employee
 */
class EmployeeProfileResource extends JsonResource
{
    public const RELATIONS = [
        'educations.certificateFile', 'familyMembers',
        'activeAssignments.position.division', 'activeAssignments.subUnit', 'activeAssignments.placementObjects.category',
        'activationItems.activation', 'terminationItems.termination',
        'bankAccounts', 'costCenterHistories.costCenter',
    ];

    /** Relasi tambahan untuk tanggal acuan: semua penugasan (untuk jabatan & penempatan pada tanggal itu). */
    public const RELATIONS_FOR_PERIOD = [
        ...self::RELATIONS,
        'assignments.position.division', 'assignments.subUnit', 'assignments.placementObjects.category',
    ];

    /** Tanggal acuan periode; null = hari ini (perilaku biasa). */
    private ?Carbon $asOf = null;

    /** Hitung status, masa kerja, dan jabatan utama per tanggal acuan (lihat App\Support\DataPeriod). */
    public function asOf(?Carbon $date): static
    {
        $this->asOf = $date;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $highestEducation = $this->highestEducation();
        $statusService = app(EmployeeStatusService::class);

        return [
            'id' => $this->id,

            // --- Biodata ---
            'employment_status' => $this->employment_status,
            'nik' => $this->nik,
            /** No. Registrasi karyawan; null kalau belum ada. */
            'registration_number' => $this->registration_number,
            'name' => $this->name,
            'ktp_number' => $this->ktp_number,
            'gender' => $this->gender,
            'birth_place' => $this->birth_place,
            /** Format YYYY-MM-DD. */
            'birth_date' => $this->birth_date?->toDateString(),
            /** Tanggal masuk, format YYYY-MM-DD. */
            'hire_date' => $this->hire_date?->toDateString(),
            'religion' => $this->religion,
            'marital_status' => $this->marital_status,
            'phone' => $this->phone,
            /** null = belum diisi. */
            'whatsapp' => $this->whatsapp,
            'email' => $this->email,
            'address' => $this->address,
            /** Alamat gambar foto (lewat front end). null kalau belum ada foto. */
            'photo_url' => $this->photoUrl(),

            // --- Pendidikan (urut dari jenjang tertinggi) ---
            'educations' => array_map(fn (EmployeeEducation $education) => $this->education($education), $this->educationsFromHighest()),
            /** Pendidikan terakhir (jenjang tertinggi). "-" kalau belum ada riwayat. */
            'education' => $highestEducation?->level ?? '-',
            'major' => $highestEducation?->major,

            // --- Data batih ---
            'family_members' => $this->familyMembers->map(fn (EmployeeFamilyMember $member) => [
                'id' => $member->id,
                'relationship' => $member->relationship,
                'name' => $member->name,
                'ktp_number' => $member->ktp_number,
                'gender' => $member->gender,
                'birth_place' => $member->birth_place,
                'birth_date' => $member->birth_date->toDateString(),
                'occupation' => $member->occupation,
                'dependent_status' => $member->dependent_status,
            ]),

            // --- Status kepegawaian (per tanggal acuan kalau ada periode, lihat asOf()) ---
            /** Belum Aktif / Aktif / Nonaktif. */
            'active_status' => $this->asOf === null
                ? $this->active_status
                : $statusService->statusOn($this->resource, $this->asOf)->value,
            'inactive_since' => $this->asOf === null
                ? $this->inactive_since?->toDateString()
                : $statusService->inactiveSince($this->resource, $this->asOf)?->toDateString(),
            /** Masa kerja dalam bulan penuh, TANPA masa nonaktif (0 kalau belum pernah aktif). */
            'service_months' => $statusService->serviceMonths($this->resource, $this->asOf),
            /** Isian wajib yang belum diisi, mis. ["No. KTP", "Agama"]. Kosong = lengkap. */
            'missing_fields' => $this->missingFields(),

            // --- Penempatan ---
            /** Jabatan utama (Definitif, atau BRM tertinggi) dari penugasan aktif; null = belum ditempatkan. */
            'position' => $this->mainPosition(),
            /** SEMUA penugasan yang berjalan, jabatan utama di indeks 0; [] = belum ditempatkan. */
            'active_positions' => $this->activePositions(),

            // --- Data dasar upah (yang berlaku hari ini / pada tanggal acuan periode) ---
            /** { bank_name, account_number, account_holder, effective_date }, atau null. */
            'bank_account' => EmployeeBankAccountResource::summary($this->bankAccountOn($this->asOf ?? DataPeriod::asOfOrToday(null))),
            /** { id, code, name, effective_date }, atau null. */
            'cost_center' => EmployeeCostCenterResource::summary($this->costCenterOn($this->asOf ?? DataPeriod::asOfOrToday(null))),
        ];
    }

    /** Jabatan utama beserta penempatannya (bentuk sama dengan placementSummary()), atau null. */
    private function mainPosition(): ?array
    {
        $assignment = $this->mainAssignment($this->asOf);

        return $assignment === null ? null : $this->placementSummary($assignment);
    }

    /**
     * Semua penugasan yang berjalan pada tanggal acuan, urut prioritas (Definitif dulu, lalu BRM tertinggi).
     * Urutannya sama dengan mainAssignment(), jadi jabatan utama selalu di indeks 0.
     *
     * @return list<array<string, mixed>>
     */
    private function activePositions(): array
    {
        return $this->assignmentsByPriorityOn($this->asOf)
            ->map(fn (PositionAssignment $assignment) => $this->placementSummary($assignment))
            ->values()
            ->all();
    }

    /**
     * Ringkasan satu penugasan: jabatan, bidang, sub unit, dan objek penempatan.
     * Objek penempatan hanya diambil yang PERTAMA, karena ke depan satu penugasan hanya boleh 1 objek.
     *
     * @return array{
     *     assignment_id: string,
     *     id: string,
     *     title: string,
     *     brm_level: int,
     *     job_grade: int|null,
     *     assignment_status: string,
     *     division: array{id: string, name: string}|null,
     *     sub_unit: array{id: string, name: string, afdeling_code: string|null},
     *     placement_object: array{id: string, name: string, category_name: string|null}|null,
     * }
     */
    private function placementSummary(PositionAssignment $assignment): array
    {
        $position = $assignment->position;
        $division = $position->division;
        $subUnit = $assignment->subUnit;
        $placementObject = $assignment->placementObjects->first();

        return [
            'assignment_id' => $assignment->id,
            'id' => $position->id,
            'title' => $position->title,
            'brm_level' => $position->brm_level,
            'job_grade' => $position->job_grade,
            'assignment_status' => $assignment->assignment_status,
            'division' => $division === null ? null : [
                'id' => $division->id,
                'name' => $division->name,
            ],
            'sub_unit' => [
                'id' => $subUnit->id,
                'name' => $subUnit->name,
                'afdeling_code' => $subUnit->afdeling_code,
            ],
            'placement_object' => $placementObject === null ? null : [
                'id' => $placementObject->id,
                'name' => $placementObject->name,
                'category_name' => $placementObject->category?->name,
            ],
        ];
    }

    /** Satu riwayat pendidikan + keterangan & alamat file ijazahnya. */
    private function education(EmployeeEducation $education): array
    {
        return [
            'id' => $education->id,
            'level' => $education->level,
            'institution' => $education->institution,
            'major' => $education->major,
            'city' => $education->city,
            'start_year' => $education->start_year === null ? null : (string) $education->start_year,
            'graduation_year' => (string) $education->graduation_year,
            'gpa' => $education->gpa,
            'title_front' => $education->title_front,
            'title_back' => $education->title_back,
            'certificate_number' => $education->certificate_number,
            'certificate_file' => FileStorage::info($education->certificateFile),
            'certificate_file_url' => $education->certificate_file_id
                ? $this->fileUrl("type=ijazah&education={$education->id}")
                : null,
        ];
    }

    /** Alamat file karyawan lewat front end, mis. "/api/employees/{id}/files?type=foto". */
    private function fileUrl(string $query): string
    {
        return config('hr.file_url_prefix')."/employees/{$this->id}/files?{$query}";
    }
}
