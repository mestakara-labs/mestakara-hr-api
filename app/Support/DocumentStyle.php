<?php

namespace App\Support;

/**
 * GAYA BERSAMA semua dokumen yang dibuat sistem (Excel, PDF, Word): penulisan nama perusahaan & font bawaan.
 * Ubah di sini saja supaya seluruh dokumen ikut berubah.
 *
 *   $spreadsheet->getDefaultStyle()->getFont()->setName(DocumentStyle::FONT)->setSize(DocumentStyle::FONT_SIZE);
 */
class DocumentStyle
{
    /** Penulisan nama perusahaan di semua dokumen. */
    public const COMPANY = 'PT. Perkebunan Nusantara I (Persero) Regional 2';

    /** Penulisan singkat, HANYA untuk tempat yang terlalu sempit untuk nama lengkap (kop SPKL: judul harus tepat di tengah). */
    public const COMPANY_SHORT = 'PTPN I (Persero) Regional 2';

    /** Font bawaan semua dokumen. */
    public const FONT = 'Tahoma';

    /** Ukuran font bawaan (pt). */
    public const FONT_SIZE = 11;

    /** Lebar kolom Nama di Excel (satuan lebar kolom); nama yang lebih panjang berlipat ke bawah supaya kolom tidak dominan. */
    public const NAME_COLUMN_WIDTH = 20;
}
