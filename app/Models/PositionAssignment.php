<?php

namespace App\Models;

use App\Enums\AssignmentPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penugasan jabatan (tabel position_assignments) = "Jabatan Karyawan":
 * karyawan X memegang jabatan Y di sub unit Z dengan status S sejak TMT.
 *
 * Penugasan dibuat & diakhiri lewat Mutasi & Penempatan / Penambahan / Penonaktifan, tidak diubah langsung.
 * Atasan langsung disimpan sebagai PENUGASAN atasan (bukan karyawan), karena satu orang bisa memegang beberapa jabatan.
 */
#[Fillable([
    'employee_id', 'position_id', 'sub_unit_id', 'assignment_status', 'start_date', 'end_date',
    'decree_type', 'decree_number', 'decree_date', 'decree_file_id', 'transfer_id', 'notes',
])]
class PositionAssignment extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'decree_date' => 'date',
        ];
    }

    // ---------------------------------------------------------------------
    // Relasi
    // ---------------------------------------------------------------------

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function subUnit(): BelongsTo
    {
        return $this->belongsTo(SubUnit::class);
    }

    /** Usulan mutasi yang membuat penugasan ini (null = data awal / dari Penambahan). */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function decreeFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'decree_file_id');
    }

    /** Objek penempatan, urut sesuai urutan master Objek Penempatan. */
    public function placementObjects(): BelongsToMany
    {
        return $this->belongsToMany(PlacementObject::class, 'assignment_placement_objects', 'assignment_id')
            ->orderBy('placement_objects.id');
    }

    /** Atasan langsung = penugasan milik atasan. */
    public function supervisors(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'assignment_supervisors', 'assignment_id', 'supervisor_assignment_id');
    }

    /** Kebalikan dari supervisors(): penugasan yang melapor ke penugasan ini. */
    public function subordinates(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'assignment_supervisors', 'supervisor_assignment_id', 'assignment_id');
    }

    public function supervisorChanges(): HasMany
    {
        return $this->hasMany(SupervisorChange::class, 'assignment_id');
    }

    /**
     * Penugasan LAIN yang beratasan salah satu penugasan di $supervisorIds (penugasan di daftar itu sendiri dilewati).
     * Membaca tabel pivot langsung, karena relasi ke tabel yang sama sulit disaring dengan whereHas.
     *
     * @param  list<string>  $supervisorIds
     * @return list<string>
     */
    public static function subordinateIdsOf(array $supervisorIds): array
    {
        return DB::table('assignment_supervisors')
            ->whereIn('supervisor_assignment_id', $supervisorIds)
            ->whereNotIn('assignment_id', $supervisorIds)
            ->distinct()
            ->pluck('assignment_id')
            ->all();
    }

    // ---------------------------------------------------------------------
    // Masa tugas: Akan Datang / Aktif / Selesai
    // ---------------------------------------------------------------------

    /**
     * Hanya penugasan yang sedang berjalan pada tanggal tertentu (bawaan: hari ini):
     * TMT mulai ≤ tanggal, dan TMT selesai kosong atau ≥ tanggal.
     */
    public function scopeActive(Builder $query, ?Carbon $date = null): void
    {
        $day = ($date ?? Carbon::today())->toDateString();

        $query->whereDate('start_date', '<=', $day)
            ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $day));
    }

    /** Masa tugas pada hari ini: Akan Datang, Aktif, atau Selesai. */
    public function period(): AssignmentPeriod
    {
        $today = Carbon::today();

        if ($this->start_date->greaterThan($today)) {
            return AssignmentPeriod::Upcoming;
        }

        if ($this->end_date !== null && $this->end_date->lessThan($today)) {
            return AssignmentPeriod::Finished;
        }

        return AssignmentPeriod::Active;
    }

    public function isActive(): bool
    {
        return $this->period() === AssignmentPeriod::Active;
    }
}
