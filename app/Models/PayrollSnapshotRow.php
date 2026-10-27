<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * POTRET satu baris daftar upah saat periode dikunci (tabel payroll_snapshot_rows). Kolomnya sama dengan
 * baris hasil PayrollCalculator; dibuat & dihapus utuh oleh PayrollLockService.
 */
class PayrollSnapshotRow extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];
}
