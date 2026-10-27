<?php

namespace App\Enums;

/**
 * Status usulan mutasi (transfers.status).
 *
 *   Draf ──(Simpan & Lanjutkan, jalur Template)──> Menunggu Dokumen ──(unggah scan SK)──> Berlaku
 *   Draf ──(Simpan & Lanjutkan, jalur Unggah)─────────────────────────────────────────────> Berlaku
 *   Menunggu Dokumen / Berlaku ──(Batalkan)──> Dibatalkan
 */
enum TransferStatus: string
{
    use HasValues;

    case Draft = 'Draf';
    case WaitingDocument = 'Menunggu Dokumen';
    case Effective = 'Berlaku';
    case Cancelled = 'Dibatalkan';

    /** Usulan yang masih bisa diubah isinya (Draf & Menunggu Dokumen) = "belum selesai". */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::WaitingDocument;
    }
}
