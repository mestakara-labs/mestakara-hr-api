<?php

namespace App\Services;

use App\Models\PositionAssignment;
use App\Support\StatusDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * Menyimpan SATU dokumen dasar (kolom document_* & signer_* + file) ke model yang memakai
 * trait HasStatusDocument: usulan Penambahan / Penonaktifan dan itemnya.
 *
 * File: UploadedFile = file baru (file lama dihapus), true = pertahankan file lama, null = hapus.
 * Model belum disimpan ke database; pemanggil yang memanggil ->save().
 */
class StatusDocumentService
{
    public function __construct(private FileStorage $fileStorage) {}

    /** Isi kolom dokumen dari isian (lihat StatusDocument::read) dan ganti filenya. */
    public function fill(Model $target, array $values, UploadedFile|bool|null $file, string $folder): void
    {
        $columns = StatusDocument::toColumns($values);

        // Pejabat penandatangan yang tidak dikenal (mis. di Draf) disimpan kosong, supaya tidak melanggar foreign key.
        if ($columns['signer_assignment_id'] !== null && ! PositionAssignment::whereKey($columns['signer_assignment_id'])->exists()) {
            $columns['signer_assignment_id'] = null;
        }

        $target->fill($columns);
        $target->document_file_id = $this->replaceFile($target, $file, $folder);
    }

    /** Kosongkan dokumen (mis. saat cakupan diganti dari Kolektif ke Per Orang). */
    public function clear(Model $target): void
    {
        $this->fill($target, StatusDocument::read([]), null, '');
    }

    /** Ganti file dokumen; hasilnya id file yang dipakai sekarang (atau null). */
    private function replaceFile(Model $target, UploadedFile|bool|null $file, string $folder): ?string
    {
        if ($file === true) {
            return $target->document_file_id;
        }

        $oldFile = $target->documentFile;
        $newId = $file instanceof UploadedFile ? $this->fileStorage->store($file, $folder)->id : null;
        $this->fileStorage->delete($oldFile);
        $target->unsetRelation('documentFile');

        return $newId;
    }
}
