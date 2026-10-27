<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Aturan file unggahan — SAMA dengan front end (src/lib/file-rules.ts).
 *
 *   Foto            JPG/PNG,        maks. 2 MB
 *   Dokumen / SK    PDF/JPG/PNG,    maks. 5 MB
 *   Ijazah          PDF/JPG/PNG,    maks. 5 MB
 *
 * Contoh:
 *   $error = UploadRule::photo()->check($request->file('photo'));   // null = boleh
 */
class UploadRule
{
    /**
     * @param  string  $label  nama isian untuk pesan error, mis. "Foto"
     * @param  list<string>  $extensions  ekstensi yang boleh, huruf kecil
     */
    public function __construct(
        public string $label,
        public array $extensions,
        public int $maxSizeMb,
    ) {}

    public static function photo(): self
    {
        return new self('Foto', ['jpg', 'jpeg', 'png'], 2);
    }

    public static function document(): self
    {
        return new self('File Dokumen', ['pdf', 'jpg', 'jpeg', 'png'], 5);
    }

    public static function certificate(): self
    {
        return new self('File Ijazah', ['pdf', 'jpg', 'jpeg', 'png'], 5);
    }

    /** Periksa satu file. Hasilnya pesan error, atau null kalau file boleh diunggah. */
    public function check(UploadedFile $file): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, $this->extensions, true)) {
            return "Format {$this->label} harus ".strtoupper(implode(', ', $this->extensions));
        }

        if ($file->getSize() > $this->maxSizeMb * 1024 * 1024) {
            return "Ukuran {$this->label} maksimal {$this->maxSizeMb} MB";
        }

        return null;
    }
}
