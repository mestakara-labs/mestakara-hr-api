<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu riwayat pendidikan karyawan (tabel employee_educations), mis. S1 Akuntansi lulus 2015.
 */
#[Fillable([
    'employee_id', 'level', 'institution', 'major', 'city', 'start_year', 'graduation_year',
    'gpa', 'title_front', 'title_back', 'certificate_number', 'certificate_file_id',
])]
class EmployeeEducation extends Model
{
    use HasUuids;

    protected $table = 'employee_educations';

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function certificateFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'certificate_file_id');
    }
}
