<?php

namespace App\Http\Requests\Dashboard;

use App\Services\Dashboard\AnalyticsQuery;
use App\Services\Dashboard\AnalyticsService;
use App\Services\Dashboard\Dimensions;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Isian analitik = periode & filter dashboard (DashboardRequest) ditambah:
 *
 *   topic=workforce|payroll      (attendance, overtime, movement menyusul -> 422)
 *   metric=...                   kosong = metrik pertama topik itu (lihat AnalyticsService::METRICS)
 *   group_by=division            wajib; split_by=employment_status (opsional, beda dengan group_by)
 *   sort=value_desc|value_asc|label (bawaan value_desc; group_by=month: label)   limit=1..500
 *
 * Dimensi yang tidak berlaku untuk topik -> 422.
 */
class AnalyticsRequest extends DashboardRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'topic' => ['required', Rule::in(array_keys(AnalyticsService::TOPICS))],
            'metric' => ['nullable', 'string'],
            /** @example division */
            'group_by' => ['required', Rule::in(array_keys(Dimensions::LABELS))],
            'split_by' => ['nullable', Rule::in(array_keys(Dimensions::LABELS)), 'different:group_by'],
            'sort' => ['nullable', Rule::in(AnalyticsQuery::SORTS)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'topic.required' => 'Topik wajib dipilih',
            'topic.in' => 'Topik hanya boleh: '.implode(', ', array_keys(AnalyticsService::TOPICS)),
            'group_by.required' => 'Dimensi pengelompokan (group_by) wajib dipilih',
            'group_by.in' => 'Dimensi tidak dikenal',
            'split_by.in' => 'Dimensi tidak dikenal',
            'split_by.different' => 'split_by harus berbeda dengan group_by',
            'sort.in' => 'Urutan hanya boleh: '.implode(', ', AnalyticsQuery::SORTS),
            'limit.integer' => 'Batas baris harus angka',
            'limit.min' => 'Batas baris minimal 1',
            'limit.max' => 'Batas baris maksimal 500',
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $topic = $this->input('topic');
            if (! in_array($topic, AnalyticsService::AVAILABLE_TOPICS, true)) {
                $validator->errors()->add('topic', 'Analitik topik '.AnalyticsService::TOPICS[$topic].' belum tersedia');

                return;
            }
            if ($this->filled('metric') && ! isset(AnalyticsService::METRICS[$topic][$this->input('metric')])) {
                $validator->errors()->add('metric', 'Metrik untuk topik ini hanya boleh: '.implode(', ', array_keys(AnalyticsService::METRICS[$topic])));
            }
            foreach (['group_by', 'split_by'] as $field) {
                if ($this->filled($field) && ! in_array($this->input($field), AnalyticsService::DIMENSIONS[$topic], true)) {
                    $validator->errors()->add($field, 'Dimensi '.Dimensions::LABELS[$this->input($field)].' tidak berlaku untuk topik '.AnalyticsService::TOPICS[$topic]);
                }
            }
        }];
    }

    public function analyticsQuery(): AnalyticsQuery
    {
        $topic = $this->input('topic');

        return new AnalyticsQuery(
            topic: $topic,
            metric: $this->input('metric') ?? array_key_first(AnalyticsService::METRICS[$topic]),
            groupBy: $this->input('group_by'),
            splitBy: $this->input('split_by'),
            period: $this->period(),
            filters: $this->filters(),
            // Bawaan: terbesar dulu; per bulan: urut bulan.
            sort: $this->input('sort') ?? ($this->input('group_by') === 'month' ? 'label' : 'value_desc'),
            limit: $this->filled('limit') ? (int) $this->input('limit') : null,
        );
    }
}
