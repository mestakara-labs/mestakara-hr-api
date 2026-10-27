<?php

namespace App\Enums;

/**
 * Jenis dokumen dasar: dokumen dasar Mutasi (basis_document_type) dan dokumen usulan
 * Penambahan / Penonaktifan (document_type). Penandatangan WAJIB kalau jenisnya SK.
 */
enum DocumentType: string
{
    use HasValues;

    case Decree = 'SK';
    case Memo = 'Memo';
    case OfficialNote = 'Nota Dinas';
    case Disposition = 'Disposisi';
    case Request = 'Surat Permohonan';
    case Other = 'Lainnya';
}
