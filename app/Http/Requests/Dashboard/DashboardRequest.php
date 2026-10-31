<?php

namespace App\Http\Requests\Dashboard;

use App\Services\Dashboard\DashboardFilters;
use App\Services\Dashboard\DashboardPeriod;
use App\Services\Dashboard\Dimensions;
use App\Support\DataPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Periode & filter dashboard:
 *
 *   from=YYYY-MM-DD & to=YYYY-MM-DD   bulan utuh; kosong = 1 Januari tahun berjalan s.d. hari ini
 *   filters[sub_unit][]=id ...        lihat DashboardFilters
 *
 * Ditolak (422): format salah, from > to, masa depan, lebih dari 24 bulan.
 */
class DashboardRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** @example 2026-01-01 */
            'from' => ['nullable', 'date_format:Y-m-d'],
            /** @example 2026-10-31 */
            'to' => ['nullable', 'date_format:Y-m-d'],
            'filters' => ['nullable', 'array:'.implode(',', Dimensions::FILTERS)],
            'filters.*' => ['array'],
            'filters.*.*' => ['string', 'max:100'],
            /** Pengelompokan tambahan untuk widget tertentu. */
            'group_by' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'from.date_format' => 'Tanggal awal harus berformat YYYY-MM-DD',
            'to.date_format' => 'Tanggal akhir harus berformat YYYY-MM-DD',
            'filters.array' => 'Filter hanya boleh: '.implode(', ', Dimensions::FILTERS),
            'filters.*.array' => 'Nilai filter harus berupa daftar, mis. filters[division][]=id',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $today = DataPeriod::today();
            foreach (['from', 'to'] as $field) {
                if ($this->filled($field) && Carbon::parse($this->input($field))->greaterThan($today)) {
                    $validator->errors()->add($field, 'Tanggal tidak boleh melewati hari ini');
                }
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $period = $this->period();
            if ($period->from->greaterThan($period->to)) {
                $validator->errors()->add('from', 'Tanggal awal tidak boleh setelah tanggal akhir');
            } elseif (count($period->months()) > DashboardPeriod::MAX_MONTHS) {
                $validator->errors()->add('from', 'Rentang periode maksimal '.DashboardPeriod::MAX_MONTHS.' bulan');
            }
        }];
    }

    public function period(): DashboardPeriod
    {
        return DashboardPeriod::make($this->input('from'), $this->input('to'));
    }

    public function filters(): DashboardFilters
    {
        return DashboardFilters::make($this->input('filters'));
    }
}
