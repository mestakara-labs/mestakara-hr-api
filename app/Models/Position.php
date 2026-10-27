<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jabatan (tabel positions) pada SK struktur organisasi.
 *
 *   BRM-1 (Manager)         -> Bidang boleh kosong
 *   BRM-2 sampai BRM-6      -> Bidang wajib
 */
#[Fillable(['code', 'title', 'division_id', 'brm_level', 'job_grade', 'description'])]
class Position extends Model
{
    use HasFactory, HasUuids;

    /** Level BRM yang tersedia: 1 = Manager (tertinggi) sampai 6 = Pelaksana. */
    public const BRM_LEVELS = [1, 2, 3, 4, 5, 6];

    /** Batas Job Grade (opsional). */
    public const JOB_GRADE_MIN = 1;

    public const JOB_GRADE_MAX = 99;

    protected function casts(): array
    {
        return [
            'brm_level' => 'integer',
            'job_grade' => 'integer',
        ];
    }

    /** Apakah jabatan dengan level BRM ini wajib punya Bidang? (mulai BRM-2) */
    public static function needsDivision(int $brmLevel): bool
    {
        return $brmLevel >= 2;
    }

    /** Apakah pemegang jabatan dengan level BRM ini wajib punya Atasan Langsung? (mulai BRM-2) */
    public static function needsSupervisor(int $brmLevel): bool
    {
        return $brmLevel >= 2;
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /** Semua penugasan di jabatan ini (aktif maupun riwayat). */
    public function assignments(): HasMany
    {
        return $this->hasMany(PositionAssignment::class);
    }

    /** Penugasan yang sedang berjalan = pemegang jabatan saat ini (boleh lebih dari satu orang). */
    public function activeAssignments(): HasMany
    {
        return $this->assignments()->active();
    }
}
