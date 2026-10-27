<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Saring daftar id: hanya yang benar-benar ada di tabel sebuah model.
 *
 * Dipakai saat menyimpan DRAF usulan (isiannya belum diperiksa lengkap), supaya id yang
 * tidak dikenal disimpan kosong dan tidak melanggar foreign key.
 *
 *   ExistingIds::of(Position::class, ['id-1', 'tidak-ada'])   -> ['id-1']
 *   ExistingIds::first(Position::class, 'tidak-ada')          -> null
 */
class ExistingIds
{
    /**
     * @param  class-string<Model>  $modelClass
     * @return list<string>
     */
    public static function of(string $modelClass, array $ids): array
    {
        $ids = array_values(array_filter($ids));

        return $ids === [] ? [] : $modelClass::whereKey($ids)->pluck('id')->all();
    }

    /** @param  class-string<Model>  $modelClass */
    public static function first(string $modelClass, ?string $id): ?string
    {
        return self::of($modelClass, [$id])[0] ?? null;
    }
}
