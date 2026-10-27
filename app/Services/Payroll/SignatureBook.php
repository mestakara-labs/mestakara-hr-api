<?php

namespace App\Services\Payroll;

use App\Enums\SignatureDocument;
use App\Enums\SignatureOwnerType;
use App\Models\PayrollSnapshotSigner;
use App\Models\SignatureSection;
use App\Models\SignatureSectionSigner;
use App\Models\SignatureSetting;

/**
 * "Buku" penanda tangan yang SIAP DICETAK: nama & jabatan sudah diisi, aturan pengganti sudah diterapkan.
 *
 *   SignatureBook::live()                 dari pengaturan sekarang (periode draf)
 *   SignatureBook::fromSnapshot($period)  dari potret saat periode dikunci
 *
 * Aturan pengganti (resolve):
 *   - susunan kelompok/bidang kosong  -> pakai susunan Umum (inherited = true)
 *   - tempat kelompok/bidang kosong   -> pakai tempat Umum
 *   - "Tanpa Kelompok" / "Tanpa Bidang" (ownerId null) & sheet REKAP -> selalu Umum
 */
class SignatureBook
{
    /**
     * @param  array<string, array{place: string|null, sections: array<string, array<int, array{label: string, signers: array<int, array{name: string, position: string}>}>>}>  $owners
     *                                                                                                                                                                                   kunci "<owner_type>:<owner_id>"; sections dikunci nilai SignatureDocument
     */
    private function __construct(private array $owners) {}

    /** Dari pengaturan sekarang. */
    public static function live(): self
    {
        $owners = [];
        $settings = SignatureSetting::query()->with(SignatureService::RELATIONS)->get();
        $service = app(SignatureService::class);

        foreach ($settings as $setting) {
            $sections = [];

            foreach (SignatureDocument::cases() as $document) {
                $documentSections = $setting->sectionsFor($document);

                if ($documentSections->isNotEmpty()) {
                    $sections[$document->value] = $documentSections->map(fn (SignatureSection $section) => [
                        'label' => $section->label,
                        'signers' => $section->signers->map(function (SignatureSectionSigner $signer) use ($service) {
                            $resolved = $service->signerArray($signer);

                            return ['name' => $resolved['name'], 'position' => $resolved['position']];
                        })->all(),
                    ])->all();
                }
            }

            $owners[self::key($setting->owner_type, $setting->owner_id)] = ['place' => $setting->place, 'sections' => $sections];
        }

        return new self($owners);
    }

    /** Dari potret periode terkunci (payroll_snapshot_signers). */
    public static function fromSnapshot(string $period): self
    {
        $owners = [];
        $rows = PayrollSnapshotSigner::query()
            ->where('period', $period)
            ->where('inherited', false)
            ->orderBy('section_order')
            ->orderBy('signer_order')
            ->get();

        foreach ($rows as $row) {
            $key = self::key($row->owner_type, $row->owner_id);
            $owners[$key]['place'] = $row->place;
            $owners[$key]['sections'][$row->document->value][$row->section_order - 1]['label'] = $row->section_label;
            $owners[$key]['sections'][$row->document->value][$row->section_order - 1]['signers'][] = [
                'name' => $row->name,
                'position' => $row->position,
            ];
        }

        // Pemilik yang mewarisi Umum tetap menyimpan tempatnya sendiri.
        $inheritedPlaces = PayrollSnapshotSigner::query()->where('period', $period)->where('inherited', true)->get();

        foreach ($inheritedPlaces as $row) {
            $owners[self::key($row->owner_type, $row->owner_id)]['place'] ??= $row->place;
            $owners[self::key($row->owner_type, $row->owner_id)]['sections'] ??= [];
        }

        return new self($owners);
    }

    /**
     * Penanda tangan satu dokumen untuk satu pemilik, setelah aturan pengganti.
     *
     * @return array{place: string, inherited: bool, sections: array<int, array{label: string, signers: array<int, array{name: string, position: string}>}>}
     */
    public function resolve(SignatureOwnerType $ownerType, ?string $ownerId, SignatureDocument $document): array
    {
        $general = $this->owners[self::key(SignatureOwnerType::General, null)] ?? null;
        $generalSections = $general['sections'][$document->value] ?? [];
        $generalPlace = $general['place'] ?? SignatureService::DEFAULT_PLACE;

        if ($ownerType === SignatureOwnerType::General || $ownerId === null) {
            return ['place' => $generalPlace, 'inherited' => $ownerType !== SignatureOwnerType::General, 'sections' => $generalSections];
        }

        $own = $this->owners[self::key($ownerType, $ownerId)] ?? null;
        $ownSections = $own['sections'][$document->value] ?? null;

        return [
            'place' => $own['place'] ?? $generalPlace,
            'inherited' => $ownSections === null,
            'sections' => $ownSections ?? $generalSections,
        ];
    }

    /**
     * Baris potret untuk payroll_snapshot_signers: Umum + setiap pemilik yang diberikan, kedua dokumen.
     * Pemilik yang mewarisi Umum disimpan sebagai satu baris inherited (hanya menyimpan tempatnya).
     *
     * @param  array<string, list<string>>  $ownerIds  nilai SignatureOwnerType => daftar id
     * @return list<array<string, mixed>>
     */
    public function snapshotRows(string $period, array $ownerIds): array
    {
        $rows = [];
        $owners = [[SignatureOwnerType::General, null]];

        foreach ($ownerIds as $ownerType => $ids) {
            foreach ($ids as $id) {
                $owners[] = [SignatureOwnerType::from($ownerType), $id];
            }
        }

        foreach ($owners as [$ownerType, $ownerId]) {
            foreach (SignatureDocument::cases() as $document) {
                $resolved = $this->resolve($ownerType, $ownerId, $document);
                $base = [
                    'period' => $period,
                    'owner_type' => $ownerType,
                    'owner_id' => $ownerId,
                    'place' => $resolved['place'],
                    'document' => $document,
                    'inherited' => $resolved['inherited'],
                ];

                if ($resolved['inherited']) {
                    $rows[] = [...$base, 'section_order' => 0, 'section_label' => '', 'signer_order' => 0, 'name' => '', 'position' => ''];

                    continue;
                }

                foreach ($resolved['sections'] as $sectionIndex => $section) {
                    foreach ($section['signers'] as $signerIndex => $signer) {
                        $rows[] = [
                            ...$base,
                            'section_order' => $sectionIndex + 1,
                            'section_label' => $section['label'],
                            'signer_order' => $signerIndex + 1,
                            'name' => $signer['name'],
                            'position' => $signer['position'],
                        ];
                    }
                }
            }
        }

        return $rows;
    }

    private static function key(SignatureOwnerType $ownerType, ?string $ownerId): string
    {
        return $ownerType->value.':'.$ownerId;
    }
}
