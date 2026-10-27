<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SATU-SATUNYA tempat menyimpan, menghapus, dan mengirim file unggahan.
 *
 * Isi file disimpan di disk Laravel (config('hr.file_disk'), bawaan: storage/app/private),
 * sedangkan keterangannya (nama asli, ukuran, jenis) dicatat di tabel files.
 *
 *   $file = $fileStorage->store($request->file('photo'), 'foto-karyawan');
 *   $employee->photo_file_id = $file->id;
 *
 *   return $fileStorage->respond($employee->photo);   // kirim ke browser
 */
class FileStorage
{
    /** Simpan file unggahan ke folder tertentu, lalu catat di tabel files. */
    public function store(UploadedFile $upload, string $folder): File
    {
        $disk = config('hr.file_disk');
        $fileName = Str::uuid7().'.'.strtolower($upload->getClientOriginalExtension());
        $path = $upload->storeAs($folder, $fileName, $disk);

        return File::create([
            'original_name' => $upload->getClientOriginalName(),
            'mime_type' => $upload->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $upload->getSize(),
            'disk' => $disk,
            'path' => $path,
            'uploaded_by' => auth()->id(),
        ]);
    }

    /** Hapus file dari disk dan dari tabel files. Aman dipanggil dengan null. */
    public function delete(?File $file): void
    {
        if ($file === null) {
            return;
        }

        Storage::disk($file->disk)->delete($file->path);
        $file->delete();
    }

    /**
     * Kirim file ke browser untuk DITAMPILKAN (PDF & gambar terbuka langsung di tab baru / <img>).
     * Kalau file fisiknya hilang dari disk, kembalikan 404.
     */
    public function respond(File $file): StreamedResponse
    {
        $disk = Storage::disk($file->disk);

        abort_unless($disk->exists($file->path), 404, 'File tidak ditemukan.');

        return $disk->response($file->path, $file->original_name, [
            'Content-Type' => $file->mime_type,
        ], 'inline');
    }

    /** Keterangan file untuk dikirim ke front end: { name, size, type }. */
    public static function info(?File $file): ?array
    {
        if ($file === null) {
            return null;
        }

        return [
            'name' => $file->original_name,
            'size' => $file->size_bytes,
            'type' => $file->mime_type,
        ];
    }
}
