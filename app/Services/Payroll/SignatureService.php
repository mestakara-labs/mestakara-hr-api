<?php

namespace App\Services\Payroll;

use App\Enums\SignatorySource;
use App\Enums\SignatureDocument;
use App\Enums\SignatureOwnerType;
use App\Models\Employee;
use App\Models\SignatureSection;
use App\Models\SignatureSectionSigner;
use App\Models\SignatureSetting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Baca & simpan susunan penanda tangan (Umum / Kelompok Daftar Upah / Bidang).
 *
 * Bentuk susunan di API (satu dokumen), urut sesuai posisi di dokumen:
 *
 *   [ { label: "Diperiksa Oleh", signers: [ { source, employee_id, name, position }, ... ] }, ... ]
 *
 * Orang "karyawan" selalu memakai NAMA & JABATAN TERBARU dari data karyawan (jabatan utama aktif; tanpa jabatan "-").
 * Susunan null = "pakai Umum"; tempat null = "pakai tempat Umum" (lihat SignatureBook).
 */
class SignatureService
{
    /** Tempat bawaan Umum kalau belum pernah diatur. */
    public const DEFAULT_PLACE = 'Gunung Mas';

    /** Relasi yang dibutuhkan untuk menampilkan nama & jabatan terbaru orang "karyawan". */
    public const RELATIONS = ['updater', 'sections.signers.employee.activeAssignments.position'];

    /** Susunan satu pemilik (Umum: ownerId null), atau null kalau belum pernah diatur. */
    public function find(SignatureOwnerType $ownerType, ?string $ownerId): ?SignatureSetting
    {
        return SignatureSetting::query()
            ->with(self::RELATIONS)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->first();
    }

    /**
     * Semua susunan satu jenis pemilik, dikunci per owner_id (Umum: kunci "").
     *
     * @return \Illuminate\Support\Collection<string, SignatureSetting>
     */
    public function allOf(SignatureOwnerType $ownerType, bool $withEmployees = false): \Illuminate\Support\Collection
    {
        return SignatureSetting::query()
            ->with($withEmployees ? self::RELATIONS : ['sections.signers'])
            ->where('owner_type', $ownerType)
            ->get()
            ->keyBy(fn (SignatureSetting $setting) => (string) $setting->owner_id);
    }

    /**
     * Simpan susunan satu pemilik. Susunan dokumen yang dikirim MENGGANTI seluruh bagian lama dokumen itu;
     * null = hapus bagiannya (pakai Umum).
     *
     * @param  array<int, array{label: string, signers: array<int, array<string, mixed>>}>|null  $payrollSections
     * @param  array<int, array{label: string, signers: array<int, array<string, mixed>>}>|null  $spklSections
     */
    public function save(
        SignatureOwnerType $ownerType,
        ?string $ownerId,
        ?string $place,
        ?array $payrollSections,
        ?array $spklSections,
    ): SignatureSetting {
        DB::transaction(function () use ($ownerType, $ownerId, $place, $payrollSections, $spklSections) {
            $setting = SignatureSetting::query()->updateOrCreate(
                ['owner_type' => $ownerType, 'owner_id' => $ownerId],
                ['place' => $place, 'updated_by' => auth()->id()],
            );
            $setting->touch();

            $this->replaceSections($setting, SignatureDocument::Payroll, $payrollSections);
            $this->replaceSections($setting, SignatureDocument::Spkl, $spklSections);
        });

        return $this->find($ownerType, $ownerId);
    }

    /**
     * Susunan satu dokumen dalam bentuk API, atau null kalau kosong (= pakai Umum).
     *
     * @return array<int, array{label: string, signers: array<int, array<string, mixed>>}>|null
     */
    public function sectionsArray(?SignatureSetting $setting, SignatureDocument $document): ?array
    {
        $sections = $setting?->sectionsFor($document) ?? new Collection;

        if ($sections->isEmpty()) {
            return null;
        }

        return $sections->map(fn (SignatureSection $section) => [
            'label' => $section->label,
            'signers' => $section->signers->map(fn (SignatureSectionSigner $signer) => $this->signerArray($signer))->all(),
        ])->all();
    }

    /**
     * Jumlah bagian & orang satu dokumen untuk daftar, atau null kalau kosong (= pakai Umum).
     *
     * @return array{sections: int, signers: int}|null
     */
    public function counts(?SignatureSetting $setting, SignatureDocument $document): ?array
    {
        $sections = $setting?->sectionsFor($document) ?? new Collection;

        if ($sections->isEmpty()) {
            return null;
        }

        return [
            'sections' => $sections->count(),
            'signers' => $sections->sum(fn (SignatureSection $section) => $section->signers->count()),
        ];
    }

    /**
     * Satu orang dalam bentuk API: { source, employee_id, name, position }.
     *
     * @return array{source: string, employee_id: string|null, name: string, position: string}
     */
    public function signerArray(SignatureSectionSigner $signer): array
    {
        if ($signer->source === SignatorySource::Employee) {
            return [
                'source' => $signer->source->value,
                'employee_id' => $signer->employee_id,
                'name' => $signer->employee->name,
                'position' => self::employeePosition($signer->employee),
            ];
        }

        return [
            'source' => $signer->source->value,
            'employee_id' => null,
            'name' => $signer->name,
            'position' => $signer->position,
        ];
    }

    /** Jabatan terbaru karyawan: jabatan utama yang aktif hari ini, atau "-". Butuh relasi activeAssignments.position. */
    public static function employeePosition(Employee $employee): string
    {
        return $employee->mainAssignment()?->position->title ?? '-';
    }

    /** Ganti seluruh bagian satu dokumen (bagian lama dihapus, orang ikut terhapus lewat cascade). */
    private function replaceSections(SignatureSetting $setting, SignatureDocument $document, ?array $sections): void
    {
        $setting->sections()->where('document', $document)->delete();

        foreach (array_values($sections ?? []) as $sectionIndex => $section) {
            $savedSection = $setting->sections()->create([
                'document' => $document,
                'sort_order' => $sectionIndex + 1,
                'label' => $section['label'],
            ]);

            foreach (array_values($section['signers']) as $signerIndex => $signer) {
                $isEmployee = $signer['source'] === SignatorySource::Employee->value;

                $savedSection->signers()->create([
                    'sort_order' => $signerIndex + 1,
                    'source' => $signer['source'],
                    'employee_id' => $isEmployee ? $signer['employee_id'] : null,
                    'name' => $isEmployee ? null : $signer['name'],
                    'position' => $isEmployee ? null : $signer['position'],
                ]);
            }
        }
    }
}
