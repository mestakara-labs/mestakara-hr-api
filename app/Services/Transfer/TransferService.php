<?php

namespace App\Services\Transfer;

use App\Enums\DocumentMode;
use App\Enums\TransferStatus;
use App\Http\Requests\Transfer\TransferRequest;
use App\Models\File;
use App\Models\PlacementObject;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\SubUnit;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Services\FileStorage;
use App\Support\ExistingIds;
use App\Support\StatusDocument;
use App\Support\UploadRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ALUR usulan Mutasi & Penempatan (semua perubahan data ada di sini, di dalam transaksi):
 *
 *   save()               simpan usulan baru / ubah usulan (Draf / Menunggu Dokumen)
 *                          action "draft"            -> Draf
 *                          "submit" + jalur Template -> Menunggu Dokumen
 *                          "submit" + jalur Unggah   -> langsung Berlaku
 *   activate()           unggah scan SK bertanda tangan -> Berlaku
 *   cancel()             batalkan (Berlaku: semua perubahannya dikembalikan)
 *   delete()             hapus usulan Draf
 *   saveBasisDocument()  simpan dokumen dasar dari halaman detail
 *   uploadExcerpt()      unggah scan petikan SK satu karyawan
 *
 * Aturan pemeriksaan isian ada di TransferRules; penerapan ke data penugasan di TransferApplier.
 */
class TransferService
{
    public function __construct(
        private FileStorage $fileStorage,
        private TransferApplier $applier,
    ) {}

    /** Simpan usulan (baru: kirim `new Transfer`). Hasilnya usulan yang sudah tersimpan. */
    public function save(Transfer $transfer, TransferRequest $request): Transfer
    {
        return DB::transaction(function () use ($transfer, $request) {
            $payload = $request->payload();
            $signerColumns = Arr::only(StatusDocument::toColumns($payload['signer']), [
                'signer_source', 'signer_assignment_id', 'signer_name', 'signer_position',
            ]);

            $transfer->fill([
                'type' => $payload['type'],
                'effective_date' => $payload['effective_date'] ?: null,
                'notes' => $payload['notes'] ?: null,
                'decree_number' => $payload['decree_number'] ?: null,
                'decree_date' => $payload['decree_date'] ?: null,
                'document_mode' => $payload['document_mode'] ?: null,
                ...$signerColumns,
                ...StatusDocument::toColumns($payload['basis'], 'basis_'),
            ]);

            // Draf: pejabat penandatangan yang tidak dikenal disimpan kosong (supaya tidak melanggar foreign key).
            foreach (['signer_assignment_id', 'basis_signer_assignment_id'] as $column) {
                $transfer->{$column} = ExistingIds::first(PositionAssignment::class, $transfer->{$column});
            }

            if (! $transfer->exists) {
                $transfer->status = TransferStatus::Draft;
                $transfer->created_by = auth()->id();
            }

            // Scan SK BARU hanya disimpan saat "simpan & lanjutkan" lewat jalur Unggah.
            $signedFile = $request->signedFile();
            $savesNewSignedFile = $request->isSubmit() && $transfer->document_mode === DocumentMode::Upload;
            if ($signedFile instanceof UploadedFile && ! $savesNewSignedFile) {
                $signedFile = null;
            }

            $transfer->basis_document_file_id = $this->replaceFile($transfer->basisDocumentFile, $request->basisFile(), 'dokumen-dasar-mutasi');
            $transfer->signed_file_id = $this->replaceFile($transfer->signedFile, $signedFile, 'sk-mutasi');
            $transfer->save();

            $this->saveItems($transfer, $payload['items']);

            // Langkah terakhir: tentukan status.
            if (! $request->isSubmit()) {
                $transfer->update(['status' => TransferStatus::Draft]);
            } elseif ($transfer->document_mode === DocumentMode::Template) {
                $transfer->update(['status' => TransferStatus::WaitingDocument]);
            } else {
                $this->applier->apply($transfer);
            }

            return $transfer->refresh();
        });
    }

    /** Pesan sukses sesuai status akhir usulan. */
    public function saveMessage(Transfer $transfer): string
    {
        return match ($transfer->status) {
            TransferStatus::Draft => 'Draf usulan mutasi berhasil disimpan.',
            TransferStatus::WaitingDocument => 'Usulan mutasi disimpan. Unduh SK, tanda tangani, lalu unggah scan-nya supaya berlaku.',
            default => 'Usulan mutasi berhasil disimpan dan sudah berlaku.',
        };
    }

    /** Unggah scan SK bertanda tangan -> Berlaku. Isian usulan sudah diperiksa ulang oleh controller. */
    public function activate(Transfer $transfer, UploadedFile $signedFile): Transfer
    {
        return DB::transaction(function () use ($transfer, $signedFile) {
            $transfer->signed_file_id = $this->replaceFile($transfer->signedFile, $signedFile, 'sk-mutasi');
            $transfer->save();

            $this->applier->apply($transfer);

            return $transfer->refresh();
        });
    }

    /**
     * Batalkan usulan.
     *   - Menunggu Dokumen -> Dibatalkan (alasan opsional)
     *   - Berlaku          -> Dibatalkan, semua perubahannya dikembalikan (alasan WAJIB)
     *   - Draf / Dibatalkan -> ditolak
     */
    public function cancel(Transfer $transfer, ?string $reason): Transfer
    {
        $reason = trim((string) $reason);

        if ($transfer->status === TransferStatus::Draft) {
            abort(409, 'Usulan berstatus Draf tidak perlu dibatalkan. Hapus saja drafnya.');
        }
        if ($transfer->status === TransferStatus::Cancelled) {
            abort(409, 'Usulan ini sudah dibatalkan.');
        }
        if ($transfer->status === TransferStatus::Effective && $reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan Pembatalan wajib diisi']);
        }

        return DB::transaction(function () use ($transfer, $reason) {
            if ($transfer->status === TransferStatus::Effective) {
                $blockedReason = $this->applier->revert($transfer, $reason);
                abort_if($blockedReason !== null, 409, (string) $blockedReason);
            }

            $transfer->update([
                'status' => TransferStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancel_reason' => $reason ?: null,
            ]);

            return $transfer->refresh();
        });
    }

    /** Hapus usulan Draf beserta semua filenya. */
    public function delete(Transfer $transfer): void
    {
        abort_if(
            $transfer->status !== TransferStatus::Draft,
            409,
            'Hanya usulan berstatus Draf yang bisa dihapus. Usulan lain dibatalkan.',
        );

        DB::transaction(function () use ($transfer) {
            $files = [$transfer->basisDocumentFile, $transfer->signedFile, ...$transfer->items->map->excerptFile];
            $transfer->delete();

            foreach ($files as $file) {
                $this->fileStorage->delete($file);
            }
        });
    }

    /**
     * Simpan dokumen dasar dari halaman detail (Draf / Menunggu Dokumen).
     *
     * @param  array  $input  isian basis_document_* & basis_signer_* (+ keep_basis_document_file)
     */
    public function saveBasisDocument(Transfer $transfer, array $input, ?UploadedFile $newFile): Transfer
    {
        abort_unless($transfer->status->isEditable(), 409, "Dokumen dasar usulan berstatus {$transfer->status->value} tidak bisa diubah.");

        $values = StatusDocument::read($input, 'basis_');
        $file = $newFile ?? (($input['keep_basis_document_file'] ?? null) === '1' && $transfer->basis_document_file_id ? true : null);

        $errors = [];
        foreach (StatusDocument::errors($values, (bool) $file) as $field => $message) {
            $errors["basis_{$field}"] = $message;
        }
        if ($newFile && $fileError = UploadRule::document()->check($newFile)) {
            $errors['basis_document_file'] = $fileError;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($transfer, $values, $file) {
            $transfer->fill(StatusDocument::toColumns($values, 'basis_'));
            $transfer->basis_document_file_id = $this->replaceFile($transfer->basisDocumentFile, $file, 'dokumen-dasar-mutasi');
            $transfer->save();

            return $transfer->refresh();
        });
    }

    /**
     * Unggah scan PETIKAN SK satu karyawan (Menunggu Dokumen / Berlaku).
     * Kalau usulan sudah berlaku, file dokumen penugasan barunya ikut diganti petikan ini.
     */
    public function uploadExcerpt(Transfer $transfer, TransferItem $item, UploadedFile $file): Transfer
    {
        abort_unless(
            in_array($transfer->status, [TransferStatus::WaitingDocument, TransferStatus::Effective], true),
            409,
            'Petikan SK hanya bisa diunggah untuk usulan Menunggu Dokumen atau Berlaku.',
        );

        return DB::transaction(function () use ($transfer, $item, $file) {
            $item->update(['excerpt_file_id' => $this->replaceFile($item->excerptFile, $file, 'petikan-sk')]);
            $item->newAssignment?->update(['decree_file_id' => $item->excerpt_file_id]);

            return $transfer->refresh();
        });
    }

    /**
     * Karyawan di usulan: ubah baris lama (scan petikannya dipertahankan), tambah baris baru,
     * hapus baris yang tidak dikirim lagi. Baris baru dari front end ber-id "baru-...".
     */
    private function saveItems(Transfer $transfer, array $rows): void
    {
        $existing = $transfer->items()->get()->keyBy('id');
        $keptIds = [];

        foreach ($rows as $row) {
            $item = $existing->get($row['id']) ?? new TransferItem(['transfer_id' => $transfer->id]);

            // Draf belum diperiksa lengkap: id yang tidak dikenal disimpan kosong, supaya tidak melanggar foreign key.
            $item->fill([
                'employee_id' => $row['employee_id'],
                'from_assignment_id' => ExistingIds::first(PositionAssignment::class, $row['from_assignment_id']),
                'position_id' => ExistingIds::first(Position::class, $row['position_id']),
                'assignment_status' => $row['assignment_status'] ?: null,
                'sub_unit_id' => ExistingIds::first(SubUnit::class, $row['sub_unit_id']),
                'end_date' => $row['end_date'] ?: null,
                'notes' => $row['notes'] ?: null,
                'restore_subordinates' => $row['restore_subordinates'],
                'new_assignment_id' => null,
            ])->save();

            $item->placementObjects()->sync(ExistingIds::of(PlacementObject::class, $row['placement_object_ids']));
            $item->supervisors()->sync(ExistingIds::of(PositionAssignment::class, $row['supervisor_assignment_ids']));
            $keptIds[] = $item->id;
        }

        foreach ($existing->except($keptIds) as $removed) {
            $excerpt = $removed->excerptFile;
            $removed->delete();
            $this->fileStorage->delete($excerpt);
        }
    }

    /**
     * Ganti sebuah file: UploadedFile = simpan file baru (file lama dihapus), true = pertahankan
     * file lama, null = hapus file lama. Hasilnya id file yang dipakai sekarang (atau null).
     */
    private function replaceFile(?File $oldFile, UploadedFile|bool|null $newFile, string $folder): ?string
    {
        if ($newFile === true) {
            return $oldFile?->id;
        }

        $newId = $newFile instanceof UploadedFile ? $this->fileStorage->store($newFile, $folder)->id : null;
        $this->fileStorage->delete($oldFile);

        return $newId;
    }
}
