<?php

namespace App\Models;

use App\Enums\AssignmentPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Keanggotaan regu kerja (tabel work_group_members): karyawan + slot saat BERGABUNG.
 * Riwayat tidak dihapus: keanggotaan diakhiri dengan mengisi end_date (tanggal terakhir).
 */
#[Fillable(['work_group_id', 'employee_id', 'start_slot_id', 'start_date', 'end_date'])]
class WorkGroupMember extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function workGroup(): BelongsTo
    {
        return $this->belongsTo(WorkGroup::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function startSlot(): BelongsTo
    {
        return $this->belongsTo(PatternSlot::class, 'start_slot_id');
    }

    /** Aktif di regu pada tanggal "YYYY-MM-DD" ini? */
    public function isActiveOn(string $date): bool
    {
        return $this->start_date->toDateString() <= $date
            && ($this->end_date === null || $date <= $this->end_date->toDateString());
    }

    /** Masa keanggotaan hari ini: Akan Datang / Aktif / Selesai. */
    public function period(): AssignmentPeriod
    {
        $today = Carbon::today()->toDateString();

        if ($this->isActiveOn($today)) {
            return AssignmentPeriod::Active;
        }

        return $this->start_date->toDateString() > $today ? AssignmentPeriod::Upcoming : AssignmentPeriod::Finished;
    }
}
