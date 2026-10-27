<?php

namespace App\Http\Requests\Proposal;

use App\Enums\DocumentScope;
use App\Enums\ProposalStatus;
use App\Support\StatusDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * DASAR form usulan Penambahan & Penonaktifan Karyawan (bentuknya sama), dikirim sebagai multipart/form-data:
 *
 *   - document_scope     "Kolektif" atau "Per Orang"
 *   - document_* & signer_*   dokumen KOLEKTIF (jenis, nomor, tanggal, penandatangan)
 *   - notes
 *   - items              daftar karyawan (teks JSON), termasuk isian dokumen PER ORANG
 *   - action             "draft" (Simpan sebagai Draf) atau "submit" (Simpan & Berlakukan)
 *   - file document_file (kolektif) / document_file_<id item> (per orang)
 *   - keep_document_file = "1" (kolektif) / item.keep_document_file = true (per orang): file lama dipertahankan
 *
 * Kelas turunan menentukan isian khusus tiap item (itemFields) dan aturan pemeriksaannya (errors).
 */
abstract class ProposalRequest extends FormRequest
{
    /** Usulan yang sedang diubah (null saat tambah baru). */
    abstract public function currentProposal(): ?Model;

    /** Pesan 409 kalau usulan yang diubah sudah bukan Draf. */
    abstract protected function notEditableMessage(string $status): string;

    /** Hanya usulan Draf yang boleh diubah: ditolak (409) SEBELUM isiannya diperiksa. */
    protected function prepareForValidation(): void
    {
        $current = $this->currentProposal();

        if ($current !== null && $current->status !== ProposalStatus::Draft) {
            abort(409, $this->notEditableMessage($current->status->value));
        }
    }

    /** Isian khusus satu item, mis. type, effective_date, ... (semua teks; "" = kosong). */
    abstract protected function itemFields(array $row): array;

    /**
     * Jalankan aturan bisnis atas payload(). Hasilnya [nama isian => pesan atau daftar pesan].
     *
     * @return array<string, string|list<string>>
     */
    abstract protected function errors(array $payload): array;

    public function rules(): array
    {
        return [
            /** "Kolektif" atau "Per Orang". */
            'document_scope' => ['nullable', 'string'],
            /** "draft" = Simpan sebagai Draf; "submit" = Simpan & Berlakukan. */
            'action' => ['nullable', 'string'],
            /** Daftar karyawan, teks JSON. */
            'items' => ['nullable', 'string'],
            'document_file' => ['nullable', 'file'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ($this->errors($this->payload()) as $field => $messages) {
                    foreach ((array) $messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            },
        ];
    }

    /** Simpan & Berlakukan (pemeriksaan lengkap)? false = Simpan sebagai Draf. */
    public function isSubmit(): bool
    {
        return $this->input('action') === 'submit';
    }

    /**
     * Isian usulan yang sudah dirapikan:
     *
     *   document_scope, notes,
     *   document      => isian dokumen kolektif (StatusDocument::read),
     *   document_file => UploadedFile (baru) | true (file lama dipertahankan) | null,
     *   items         => [[id, employee_id, ...itemFields, document, document_file], ...]
     */
    public function payload(): array
    {
        $current = $this->currentProposal();
        $currentItems = $current?->items()->get()->keyBy('id') ?? collect();

        $items = [];
        foreach ($this->rawItems() as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            $keepsFile = filter_var($row['keep_document_file'] ?? false, FILTER_VALIDATE_BOOL);

            $items[] = [
                'id' => $id,
                'employee_id' => trim((string) ($row['employee_id'] ?? '')),
                ...$this->itemFields($row),
                'document' => StatusDocument::read($row),
                'document_file' => $this->file("document_file_{$id}")
                    ?? ($keepsFile && $currentItems->get($id)?->document_file_id ? true : null),
            ];
        }

        return [
            'document_scope' => trim((string) $this->input('document_scope')) ?: DocumentScope::Collective->value,
            'notes' => trim((string) $this->input('notes')),
            'document' => StatusDocument::read($this->all()),
            'document_file' => $this->file('document_file')
                ?? ($this->input('keep_document_file') === '1' && $current?->document_file_id ? true : null),
            'items' => $items,
        ];
    }

    /** Teks satu isian item ("" = kosong). */
    protected function text(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }

    /** Daftar teks satu isian item, mis. placement_object_ids. */
    protected function list(array $row, string $key): array
    {
        return array_values(array_unique(array_map('strval', (array) ($row[$key] ?? []))));
    }

    /** Daftar item dari teks JSON "items". */
    private function rawItems(): array
    {
        $rows = json_decode((string) $this->input('items', '[]'), true);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }
}
