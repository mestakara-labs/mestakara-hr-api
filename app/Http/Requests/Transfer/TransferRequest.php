<?php

namespace App\Http\Requests\Transfer;

use App\Models\Transfer;
use App\Services\Transfer\TransferRules;
use App\Support\StatusDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * Isian form usulan mutasi (tambah & ubah), dikirim sebagai multipart/form-data:
 *
 *   - type, effective_date, notes, decree_number, decree_date, document_mode    (teks)
 *   - signer_source, signer_assignment_id, signer_name, signer_position          (penandatangan SK)
 *   - basis_document_type/number/date + basis_signer_*                           (dokumen dasar, opsional)
 *   - items              daftar karyawan (teks JSON, lihat TransferItemPayload di front end)
 *   - action             "draft" (simpan sebagai Draf) atau "submit" (simpan & lanjutkan)
 *   - signed_file        scan SK bertanda tangan (jalur Unggah), atau keep_signed_file = "1"
 *   - basis_document_file  file dokumen dasar, atau keep_basis_document_file = "1"
 *
 * Semua aturan bisnis diperiksa di App\Services\Transfer\TransferRules.
 */
class TransferRequest extends FormRequest
{
    /** Hanya usulan Draf & Menunggu Dokumen yang boleh diubah: ditolak (409) SEBELUM isiannya diperiksa. */
    protected function prepareForValidation(): void
    {
        $current = $this->currentTransfer();

        if ($current !== null && ! $current->status->isEditable()) {
            abort(409, "Usulan berstatus {$current->status->value} tidak bisa diubah.");
        }
    }

    public function rules(): array
    {
        return [
            /** Penempatan Awal / Rotasi / Promosi / Demosi / Penugasan Sementara. */
            'type' => ['nullable', 'string'],
            /** "draft" = simpan sebagai Draf; "submit" = simpan & lanjutkan. */
            'action' => ['nullable', 'string'],
            /** Daftar karyawan, teks JSON. */
            'items' => ['nullable', 'string'],
            'signed_file' => ['nullable', 'file'],
            'basis_document_file' => ['nullable', 'file'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $errors = app(TransferRules::class)->errors(
                    $this->payload(),
                    $this->isSubmit(),
                    $this->currentTransfer(),
                    $this->signedFile(),
                    $this->basisFile(),
                );

                foreach ($errors as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            },
        ];
    }

    /** Simpan & lanjutkan (pemeriksaan lengkap)? false = simpan sebagai Draf. */
    public function isSubmit(): bool
    {
        return $this->input('action') !== 'draft';
    }

    /** Usulan yang sedang diubah, atau null saat tambah baru. */
    public function currentTransfer(): ?Transfer
    {
        return $this->route('transfer');
    }

    /** Scan SK: file baru, true = file lama dipertahankan, null = tidak ada. */
    public function signedFile(): UploadedFile|bool|null
    {
        return $this->file('signed_file')
            ?? ($this->input('keep_signed_file') === '1' && $this->currentTransfer()?->signed_file_id ? true : null);
    }

    /** File dokumen dasar: file baru, true = file lama dipertahankan, null = tidak ada. */
    public function basisFile(): UploadedFile|bool|null
    {
        return $this->file('basis_document_file')
            ?? ($this->input('keep_basis_document_file') === '1' && $this->currentTransfer()?->basis_document_file_id ? true : null);
    }

    /**
     * Isian usulan yang sudah dirapikan (semua teks; "" = kosong). Bentuknya dijelaskan di TransferRules.
     */
    public function payload(): array
    {
        $text = fn (string $key) => trim((string) $this->input($key, ''));

        return [
            'type' => $text('type'),
            'effective_date' => $text('effective_date'),
            'notes' => $text('notes'),
            'decree_number' => $text('decree_number'),
            'decree_date' => $text('decree_date'),
            'document_mode' => $text('document_mode'),
            'signer' => StatusDocument::read($this->all()),
            'basis' => StatusDocument::read($this->all(), 'basis_'),
            'items' => $this->items(),
        ];
    }

    /** Daftar karyawan dari teks JSON "items". */
    private function items(): array
    {
        $rows = json_decode((string) $this->input('items', '[]'), true);

        if (! is_array($rows)) {
            return [];
        }

        $items = [];
        foreach (array_filter($rows, 'is_array') as $row) {
            $items[] = [
                'id' => (string) ($row['id'] ?? ''),
                'employee_id' => (string) ($row['employee_id'] ?? ''),
                'from_assignment_id' => (string) ($row['from_assignment_id'] ?? ''),
                'position_id' => (string) ($row['position_id'] ?? ''),
                'assignment_status' => (string) ($row['assignment_status'] ?? ''),
                'sub_unit_id' => (string) ($row['sub_unit_id'] ?? ''),
                'placement_object_ids' => array_values(array_unique(array_map('strval', (array) ($row['placement_object_ids'] ?? [])))),
                'supervisor_assignment_ids' => array_values(array_unique(array_map('strval', (array) ($row['supervisor_assignment_ids'] ?? [])))),
                'end_date' => trim((string) ($row['end_date'] ?? '')),
                'notes' => trim((string) ($row['notes'] ?? '')),
                'restore_subordinates' => filter_var($row['restore_subordinates'] ?? false, FILTER_VALIDATE_BOOL),
            ];
        }

        return $items;
    }
}
