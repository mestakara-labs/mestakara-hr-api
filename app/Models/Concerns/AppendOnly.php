<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Untuk tabel RIWAYAT / LOG yang hanya boleh DITAMBAH: catatan yang sudah tersimpan tidak boleh diubah
 * atau dihapus lewat model. Koreksi = tambah catatan baru.
 *
 * Catatan: ikut terhapus kalau induknya dihapus lewat foreign key (cascade), mis. draf karyawan.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(function () {
            throw new LogicException('Catatan riwayat tidak boleh diubah. Tambahkan catatan baru.');
        });

        static::deleting(function () {
            throw new LogicException('Catatan riwayat tidak boleh dihapus.');
        });
    }
}
