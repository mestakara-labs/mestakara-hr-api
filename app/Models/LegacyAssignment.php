<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jabatan ASLI seorang karyawan (tabel legacy_assignments): jabatan, objek/unit, & kedudukan
 * seperti tertulis di data lama (belum terstandarisasi). Hanya referensi; satu data terakhir per karyawan.
 * Padanan standarnya ada di kamus NomenclatureAlias.
 */
#[Fillable(['employee_id', 'raw_position', 'raw_placement', 'raw_sub_unit', 'raw_afdeling_code', 'source', 'notes'])]
class LegacyAssignment extends Model
{
    use HasUuids;

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
