<?php

namespace App\Enums;

/**
 * Status usulan Penambahan & Penonaktifan Karyawan.
 *
 *   Draf ──(Simpan & Berlakukan)──> Berlaku ──(Batalkan)──> Dibatalkan
 *
 * Hanya Draf yang bisa diubah / dihapus.
 */
enum ProposalStatus: string
{
    use HasValues;

    case Draft = 'Draf';
    case Effective = 'Berlaku';
    case Cancelled = 'Dibatalkan';
}
