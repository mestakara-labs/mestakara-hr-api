<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Hari libur (tabel holidays): hanya PENANDA di kalender jadwal, tidak otomatis meliburkan karyawan.
 */
#[Fillable(['date', 'name', 'type', 'description'])]
class Holiday extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}
