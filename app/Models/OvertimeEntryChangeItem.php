<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Detail jejak perubahan lembur (tabel overtime_entry_change_items): satu isian, nilai lama -> nilai baru (teks).
 */
#[Fillable(['overtime_entry_change_id', 'field', 'old_value', 'new_value'])]
class OvertimeEntryChangeItem extends Model
{
    use AppendOnly, HasUuids;

    public $timestamps = false;
}
