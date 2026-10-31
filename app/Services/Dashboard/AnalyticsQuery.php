<?php

namespace App\Services\Dashboard;

/**
 * ISIAN ANALITIK yang sudah divalidasi (lihat AnalyticsRequest), dipakai AnalyticsService & AnalyticsWorkbook.
 *
 *   topic     workforce | payroll (Tahap A)
 *   metric    lihat AnalyticsService::METRICS
 *   group_by  dimensi baris; split_by (opsional) dimensi kolom -> hasil berbentuk matriks
 *   sort      value_desc (bawaan) | value_asc | label (urutan baku dimensi)
 *   limit     jumlah baris yang ditampilkan (ringkasan tetap dari semua baris)
 */
class AnalyticsQuery
{
    public const SORTS = ['value_desc', 'value_asc', 'label'];

    public function __construct(
        public readonly string $topic,
        public readonly string $metric,
        public readonly string $groupBy,
        public readonly ?string $splitBy,
        public readonly DashboardPeriod $period,
        public readonly DashboardFilters $filters,
        public readonly string $sort = 'value_desc',
        public readonly ?int $limit = null,
    ) {}

    public function topicLabel(): string
    {
        return AnalyticsService::TOPICS[$this->topic];
    }

    public function metricLabel(): string
    {
        return AnalyticsService::METRICS[$this->topic][$this->metric][0];
    }

    /** orang · rupiah */
    public function unit(): string
    {
        return AnalyticsService::METRICS[$this->topic][$this->metric][1];
    }

    /** Hitung orang (sekali per kelompok), bukan menjumlahkan nilai. */
    public function countsPeople(): bool
    {
        return $this->unit() === 'orang';
    }

    /** Memakai dimensi bulan (baris atau kolom)? */
    public function usesMonth(): bool
    {
        return $this->groupBy === 'month' || $this->splitBy === 'month';
    }

    /** "Bidang" atau "Bidang dan Status Karyawan". */
    public function dimensionLabel(): string
    {
        $label = Dimensions::LABELS[$this->groupBy];

        return $this->splitBy === null ? $label : $label.' dan '.Dimensions::LABELS[$this->splitBy];
    }

    /** "Analitik SDM - Upah per Bidang - Jan – Okt 2026.xlsx" */
    public function fileName(): string
    {
        return "Analitik SDM - {$this->topicLabel()} per {$this->dimensionLabel()} - {$this->period->label()}.xlsx";
    }
}
