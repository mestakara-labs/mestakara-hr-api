<?php

namespace Tests\Feature\Dashboard;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Pengujian Analitik SDM (Tahap A: topik workforce & payroll): tanpa & dengan split_by, urutan & batas,
 * validasi dimensi / topik, dan ekspor Excel. Data uji di DashboardTestHelpers.
 */
class AnalyticsTest extends TestCase
{
    use DashboardTestHelpers, RefreshDatabase;

    private const OCTOBER = 'from=2026-10-01&to=2026-10-31';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDashboardScenario();
    }

    public function test_payroll_net_per_group_without_split(): void
    {
        $data = $this->getJson('/api/v1/analytics?topic=payroll&metric=net&group_by=payroll_group&'.self::OCTOBER)->assertOk()->json('data');

        $this->assertSame('rows', $data['type']);
        $this->assertSame('rupiah', $data['unit']);
        $this->assertSame([
            ['key' => $this->mountain->id, 'label' => 'Gunung Mas', 'value' => 2_547_600, 'share' => 62.9],
            ['key' => $this->parking->id, 'label' => 'Petugas Parkir', 'value' => 1_500_000, 'share' => 37.1],
            ['key' => 'tanpa', 'label' => 'Tanpa Kelompok', 'value' => 0, 'share' => 0],
        ], $data['rows']);
        $this->assertSame([
            'total' => $this->payrollTotal('2026-10'),
            'average' => 1_349_200,
            'count' => 3,
            'previous' => $this->payrollTotal('2026-09'),
        ], $data['summary']);
    }

    public function test_payroll_headcount_counts_each_person_once(): void
    {
        $data = $this->getJson('/api/v1/analytics?topic=payroll&metric=headcount&group_by=month&from=2026-09-01&to=2026-10-31')->assertOk()->json('data');

        // Ani, Budi, Cici dibayar di kedua bulan; total orang dalam rentang tetap 3.
        $this->assertSame(['Sep 2026', 'Okt 2026'], array_column($data['rows'], 'label'));   // per bulan: urut bulan
        $this->assertSame([3, 3], array_column($data['rows'], 'value'));
        $this->assertSame(3, $data['summary']['total']);
    }

    public function test_workforce_headcount_with_split_by_gives_matrix(): void
    {
        $data = $this->getJson('/api/v1/analytics?topic=workforce&group_by=division&split_by=gender&'.self::OCTOBER)->assertOk()->json('data');

        $this->assertSame('matrix', $data['type']);
        $this->assertSame('headcount', $data['metric']);
        $this->assertSame(['Tanpa Bidang', 'Bidang Tanaman'], array_column($data['data']['rows'], 'label'));   // terbesar dulu
        $this->assertSame(['Laki-laki', 'Perempuan'], array_column($data['data']['columns'], 'label'));
        $this->assertSame([[1, 1], [1, 0]], $data['data']['values']);
        $this->assertSame([2, 1], $data['data']['row_totals']);
        $this->assertSame([2, 1], $data['data']['column_totals']);
        $this->assertSame(3, $data['data']['total']);
        $this->assertSame(['total' => 3, 'average' => 1.5, 'count' => 2, 'previous' => 4], $data['summary']);
    }

    public function test_sort_limit_and_filters(): void
    {
        $base = '/api/v1/analytics?topic=payroll&group_by=payroll_group&'.self::OCTOBER;

        $ascending = $this->getJson("{$base}&sort=value_asc&limit=2")->assertOk()->json('data');
        $this->assertSame(['Tanpa Kelompok', 'Petugas Parkir'], array_column($ascending['rows'], 'label'));
        $this->assertSame(3, $ascending['summary']['count']);   // ringkasan tetap dari semua kelompok

        $filtered = $this->getJson("{$base}&filters[employment_status][]=Karyawan Daily Worker")->assertOk()->json('data');
        $this->assertSame([['key' => $this->parking->id, 'label' => 'Petugas Parkir', 'value' => 1_500_000, 'share' => 100]], $filtered['rows']);
    }

    public function test_invalid_topic_metric_and_dimension_are_rejected(): void
    {
        $this->getJson('/api/v1/analytics?topic=attendance&group_by=division')->assertStatus(422)
            ->assertJsonPath('errors.topic.0', 'Analitik topik Kehadiran belum tersedia');
        $this->getJson('/api/v1/analytics?topic=workforce&metric=net&group_by=division')->assertStatus(422)->assertJsonValidationErrors('metric');
        $this->getJson('/api/v1/analytics?topic=payroll&group_by=hobby')->assertStatus(422)->assertJsonValidationErrors('group_by');
        $this->getJson('/api/v1/analytics?topic=payroll&group_by=division&split_by=division')->assertStatus(422)->assertJsonValidationErrors('split_by');
        $this->getJson('/api/v1/analytics?topic=payroll')->assertStatus(422)->assertJsonValidationErrors('group_by');
        $this->getJson('/api/v1/analytics?topic=payroll&group_by=division&from=2026-12-01')->assertStatus(422)->assertJsonValidationErrors('from');
    }

    public function test_export_writes_excel_with_title_period_and_total(): void
    {
        $response = $this->get('/api/v1/analytics/export?topic=payroll&group_by=payroll_group&from=2026-09-01&to=2026-10-31&filters[payroll_group][]='.$this->mountain->id)
            ->assertOk();

        $this->assertStringContainsString(
            "filename*=UTF-8''".rawurlencode('Analitik SDM - Upah per Kelompok Daftar Upah - Sep – Okt 2026.xlsx'),
            $response->headers->get('Content-Disposition'),
        );

        $sheet = IOFactory::load($response->getFile()->getPathname())->getActiveSheet();
        $this->assertSame('Analitik SDM — Upah Diterima per Kelompok Daftar Upah', $sheet->getCell('A1')->getValue());
        $this->assertSame('Periode: Sep – Okt 2026 (01-09-2026 s.d. 31-10-2026)', $sheet->getCell('A3')->getValue());
        $this->assertSame('Filter: Kelompok Daftar Upah: Gunung Mas', $sheet->getCell('A4')->getValue());
        $this->assertSame(['No', 'Kelompok Daftar Upah', 'Upah Diterima (Rp)', 'Porsi (%)'], $sheet->rangeToArray('A6:D6')[0]);
        $this->assertSame('Gunung Mas', $sheet->getCell('B7')->getValue());
        $this->assertSame('Total', $sheet->getCell('B8')->getValue());
        $this->assertEquals($sheet->getCell('C7')->getValue(), $sheet->getCell('C8')->getValue());
    }

    public function test_export_matrix(): void
    {
        $response = $this->get('/api/v1/analytics/export?topic=workforce&group_by=division&split_by=gender&'.self::OCTOBER)->assertOk();

        $sheet = IOFactory::load($response->getFile()->getPathname())->getActiveSheet();
        $this->assertSame(['No', 'Bidang', 'Laki-laki', 'Perempuan', 'Total'], $sheet->rangeToArray('A6:E6')[0]);
        $this->assertEquals([null, 'Total', 2, 1, 3], $sheet->rangeToArray('A9:E9')[0]);
    }
}
