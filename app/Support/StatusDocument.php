<?php

namespace App\Support;

use App\Enums\DocumentType;
use App\Enums\SignerSource;
use App\Models\PositionAssignment;
use Illuminate\Http\UploadedFile;

/**
 * ATURAN DOKUMEN DASAR & PENANDATANGAN — sama dengan front end (lib/activation-rules.ts, lib/signer-rules.ts).
 * Dipakai Mutasi (dokumen dasar + SK), Penambahan, dan Penonaktifan.
 *
 * Satu dokumen = jenis + nomor + tanggal + file + penandatangan. Isian dikirim dengan nama:
 *
 *   document_type, document_number, document_date,
 *   signer_source, signer_assignment_id, signer_name, signer_position
 *
 * (Dokumen dasar Mutasi memakai nama yang sama dengan awalan "basis_", mis. basis_document_type.)
 *
 * Aturan:
 *   - dokumen boleh kosong seluruhnya (kecuali `$required`), tetapi kalau salah satu isiannya
 *     diisi, jenis + nomor + tanggal + file WAJIB;
 *   - penandatangan WAJIB kalau jenisnya SK:
 *       Unit             -> pilih pejabat yang SEDANG menjabat
 *       Regional / HO    -> nama & jabatan diketik
 */
class StatusDocument
{
    /** Nama isian satu dokumen (tanpa awalan). */
    public const FIELDS = [
        'document_type', 'document_number', 'document_date',
        'signer_source', 'signer_assignment_id', 'signer_name', 'signer_position',
    ];

    /**
     * Ambil isian dokumen dari sebuah array, mis. $request->all() dengan awalan "basis_".
     * Hasilnya selalu berisi semua FIELDS (tanpa awalan) berupa teks ("" = kosong).
     */
    public static function read(array $input, string $prefix = ''): array
    {
        $values = [];

        foreach (self::FIELDS as $field) {
            $values[$field] = trim((string) ($input[$prefix.$field] ?? ''));
        }

        return $values;
    }

    /**
     * Periksa satu dokumen. Hasilnya [nama isian (tanpa awalan) => pesan]; kosong = benar.
     * Kunci file = "document_file".
     */
    public static function errors(array $values, bool $hasFile, bool $required = false): array
    {
        $hasAnything = $values['document_type'] !== '' || $values['document_number'] !== ''
            || $values['document_date'] !== '' || $hasFile;

        if (! $required && ! $hasAnything) {
            return [];
        }

        $errors = [];

        if ($values['document_type'] === '') {
            $errors['document_type'] = 'Pilih Jenis Dokumen';
        } elseif (! in_array($values['document_type'], DocumentType::values(), true)) {
            $errors['document_type'] = 'Jenis Dokumen tidak dikenal';
        }
        if ($values['document_number'] === '') {
            $errors['document_number'] = 'Nomor Dokumen wajib diisi';
        }
        if ($values['document_date'] === '') {
            $errors['document_date'] = 'Pilih Tanggal Dokumen';
        }
        if (! $hasFile) {
            $errors['document_file'] = 'Unggah file dokumen';
        }

        $isDecree = $values['document_type'] === DocumentType::Decree->value;

        return array_merge($errors, self::signerErrors($values, $isDecree));
    }

    /**
     * Periksa dokumen + FILE yang dikirim (format & ukuran, lihat UploadRule::document).
     * `$file`: UploadedFile = file baru, true = file lama dipertahankan, null = tidak ada.
     * `$isDraft` = simpan sebagai Draf: isian boleh belum lengkap, hanya file yang diperiksa.
     */
    public static function errorsWithFile(array $values, UploadedFile|bool|null $file, bool $required, bool $isDraft = false): array
    {
        $errors = $isDraft ? [] : self::errors($values, (bool) $file, $required);

        if ($file instanceof UploadedFile && $fileError = UploadRule::document()->check($file)) {
            $errors['document_file'] = $fileError;
        }

        return $errors;
    }

    /**
     * Periksa penandatangan. `$required` = wajib diisi (mis. dokumen berjenis SK, atau SK mutasi).
     * Hasilnya [nama isian => pesan]; kosong = benar.
     */
    public static function signerErrors(array $values, bool $required): array
    {
        $hasSigner = $values['signer_source'] !== '' || $values['signer_assignment_id'] !== ''
            || $values['signer_name'] !== '' || $values['signer_position'] !== '';

        if (! $required && ! $hasSigner) {
            return [];
        }

        $source = SignerSource::tryFrom($values['signer_source']);

        if ($values['signer_source'] === '') {
            return ['signer_source' => 'Pilih asal Penandatangan'];
        }
        if ($source === null) {
            return ['signer_source' => 'Asal Penandatangan tidak dikenal'];
        }

        if ($source === SignerSource::Unit) {
            if ($values['signer_assignment_id'] === '') {
                return ['signer_assignment_id' => 'Pilih pejabat Penandatangan'];
            }

            $signer = PositionAssignment::find($values['signer_assignment_id']);

            return $signer === null || ! $signer->isActive()
                ? ['signer_assignment_id' => 'Penandatangan harus pejabat yang sedang menjabat']
                : [];
        }

        $errors = [];
        if ($values['signer_name'] === '') {
            $errors['signer_name'] = 'Nama Penandatangan wajib diisi';
        }
        if ($values['signer_position'] === '') {
            $errors['signer_position'] = 'Jabatan Penandatangan wajib diisi';
        }

        return $errors;
    }

    /**
     * Isian dokumen -> kolom yang disimpan (isian kosong jadi null). Isian penandatangan
     * yang tidak dipakai asalnya ikut dikosongkan. `$prefix` = awalan nama kolom, mis. "basis_".
     */
    public static function toColumns(array $values, string $prefix = ''): array
    {
        $source = SignerSource::tryFrom($values['signer_source']);
        $isUnit = $source === SignerSource::Unit;

        return [
            $prefix.'document_type' => $values['document_type'] ?: null,
            $prefix.'document_number' => $values['document_number'] ?: null,
            $prefix.'document_date' => $values['document_date'] ?: null,
            $prefix.'signer_source' => $source?->value,
            $prefix.'signer_assignment_id' => $isUnit ? ($values['signer_assignment_id'] ?: null) : null,
            $prefix.'signer_name' => $source && ! $isUnit ? $values['signer_name'] : null,
            $prefix.'signer_position' => $source && ! $isUnit ? $values['signer_position'] : null,
        ];
    }

    /**
     * Penandatangan tersimpan -> { source, assignment_id, name, position_title } untuk front end.
     * Unit: nama & jabatan diambil dari penugasannya (butuh relasi employee & position). null = belum diisi.
     */
    public static function signerResource(?string $source, ?PositionAssignment $assignment, ?string $name, ?string $position): ?array
    {
        if ($source === null) {
            return null;
        }

        if ($source === SignerSource::Unit->value) {
            return [
                'source' => $source,
                'assignment_id' => $assignment?->id,
                'name' => $assignment?->employee->name ?? '-',
                'position_title' => $assignment?->position->title ?? '-',
            ];
        }

        return [
            'source' => $source,
            'assignment_id' => null,
            'name' => $name ?? '-',
            'position_title' => $position ?? '-',
        ];
    }
}
