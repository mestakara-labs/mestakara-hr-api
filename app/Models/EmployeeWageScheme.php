<?php

namespace App\Models;

use App\Enums\WageType;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu catatan RIWAYAT skema upah Karyawan Casual / Daily Worker (tabel employee_wage_schemes).
 * Tidak bisa diubah/dihapus; perubahan upah = catatan baru dengan "berlaku mulai" baru.
 */
#[Fillable([
    'employee_id', 'wage_type', 'base_salary', 'working_days', 'is_capped', 'cap_days',
    'operational_position_allowance', 'incentive', 'has_meal_allowance', 'meal_allowance_per_day',
    'payroll_group_id', 'effective_date', 'notes', 'created_by',
])]
class EmployeeWageScheme extends Model
{
    use AppendOnly, HasUuids;

    /** Isian skema upah (yang disalin dari catatan lama saat membuat catatan baru). */
    public const SCHEME_FIELDS = [
        'wage_type', 'base_salary', 'working_days', 'is_capped', 'cap_days',
        'operational_position_allowance', 'incentive', 'has_meal_allowance', 'meal_allowance_per_day',
        'payroll_group_id',
    ];

    protected function casts(): array
    {
        return [
            'wage_type' => WageType::class,
            'base_salary' => 'integer',
            'working_days' => 'integer',
            'is_capped' => 'boolean',
            'cap_days' => 'integer',
            'operational_position_allowance' => 'integer',
            'incentive' => 'integer',
            'has_meal_allowance' => 'boolean',
            'meal_allowance_per_day' => 'integer',
            'effective_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollGroup(): BelongsTo
    {
        return $this->belongsTo(PayrollGroup::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Upah sehari = Gaji Pokok / HKE, dibulatkan ke rupiah terdekat. */
    public function dailyRate(): int
    {
        return (int) round($this->base_salary / $this->working_days);
    }

    /**
     * Isian skema dalam bentuk sederhana (jenis upah sebagai teks), untuk disalin atau dibandingkan.
     *
     * @return array<string, mixed>
     */
    public function schemeValues(): array
    {
        return [...$this->only(self::SCHEME_FIELDS), 'wage_type' => $this->wage_type->value];
    }
}
