<?php

namespace Tests\Feature\Dashboard;

use App\Services\Dashboard\DashboardWidgets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pengujian Dashboard (Tahap A): periode & validasi, posisi vs akumulasi, pembanding, filter,
 * angka upah sama dengan Daftar Upah, ringkasan, dan pilihan filter. Data uji di DashboardTestHelpers.
 */
class DashboardTest extends TestCase
{
    use DashboardTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDashboardScenario();
    }

    // -------------------------------------------------------------------------
    // Periode & validasi
    // -------------------------------------------------------------------------

    public function test_dashboard_returns_all_widgets_for_default_period(): void
    {
        $data = $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('success', true)->json('data');

        $this->assertSame('2026-01-01', $data['from']);
        $this->assertSame('2026-11-05', $data['to']);
        $this->assertSame('Jan – Nov 2026', $data['period_label']);
        $this->assertSame(array_keys(DashboardWidgets::TITLES), array_column($data['widgets'], 'key'));
        foreach ($data['widgets'] as $widget) {
            $this->assertSame(['key', 'title', 'from', 'to', 'filters', 'type', 'data'], array_keys($widget));
            $this->assertSame(['2026-01-01', '2026-11-05'], [$widget['from'], $widget['to']]);
        }
    }

    public function test_period_is_rounded_to_whole_months(): void
    {
        $widget = $this->widget('workforce.kpi_total', 'from=2026-09-15&to=2026-10-20');

        $this->assertSame(['2026-09-01', '2026-10-31'], [$widget['from'], $widget['to']]);
    }

    public function test_invalid_period_and_filters_are_rejected(): void
    {
        $this->getJson('/api/v1/dashboard?from=2026-10-01&to=2026-09-30')->assertStatus(422)->assertJsonValidationErrors('from');
        $this->getJson('/api/v1/dashboard?to=2026-12-01')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/dashboard?from=2024-10-01&to=2026-10-31')->assertStatus(422)
            ->assertJsonPath('errors.from.0', 'Rentang periode maksimal 24 bulan');
        $this->getJson('/api/v1/dashboard?from=01-10-2026')->assertStatus(422)->assertJsonValidationErrors('from');
        $this->getJson('/api/v1/dashboard/widgets/payroll.by_group?filters[gender][]=Perempuan')->assertStatus(422)->assertJsonValidationErrors('filters');
        $this->getJson('/api/v1/dashboard/widgets/payroll.by_group?group_by=gender')->assertStatus(422)->assertJsonValidationErrors('group_by');

        // 24 bulan masih boleh.
        $this->getJson('/api/v1/dashboard/widgets/workforce.kpi_total?from=2024-11-01&to=2026-10-31')->assertOk();
    }

    public function test_today_is_accepted_and_tomorrow_is_rejected(): void
    {
        // Hari ini 5 Nov 2026 (WIB); jam aplikasi UTC.
        foreach ($this->periodUrls('2026-11-05') as $url) {
            $this->getJson($url)->assertOk()->assertJsonPath('data.to', '2026-11-05');
        }
        foreach ($this->periodUrls('2026-11-06') as $url) {
            $this->getJson($url)->assertStatus(422)->assertJsonPath('errors.to.0', 'Tanggal tidak boleh melewati hari ini');
        }
    }

    public function test_today_follows_office_time_when_utc_is_still_yesterday(): void
    {
        $this->travelTo('2026-10-31 20:00:00');   // = 1 Nov 2026 03:00 WIB

        foreach ($this->periodUrls('2026-11-01') as $url) {
            $this->getJson($url)->assertOk()->assertJsonPath('data.to', '2026-11-01');
        }
        $this->getJson('/api/v1/dashboard?to=2026-11-02')->assertStatus(422)->assertJsonValidationErrors('to');
    }

    /** @return list<string> tiga endpoint dengan from = 1 bulan itu & to = $to */
    private function periodUrls(string $to): array
    {
        $from = substr($to, 0, 8).'01';

        return [
            "/api/v1/dashboard?from={$from}&to={$to}",
            "/api/v1/dashboard/widgets/workforce.kpi_total?from={$from}&to={$to}",
            "/api/v1/analytics?topic=workforce&group_by=division&from={$from}&to={$to}",
        ];
    }

    public function test_results_are_not_carried_to_the_next_request(): void
    {
        $this->assertSame(3, $this->widget('workforce.kpi_total')['data']['value']);
        $this->getJson('/api/v1/analytics?topic=workforce&group_by=division&'.'from=2026-10-01&to=2026-10-31')->assertJsonPath('data.summary.total', 3);

        $this->terminate($this->dina, '2026-10-20');

        $this->assertSame(2, $this->widget('workforce.kpi_total')['data']['value']);
        $this->getJson('/api/v1/analytics?topic=workforce&group_by=division&'.'from=2026-10-01&to=2026-10-31')->assertJsonPath('data.summary.total', 2);
    }

    public function test_unknown_widget_gives_404(): void
    {
        $this->getJson('/api/v1/dashboard/widgets/payroll.unknown')->assertNotFound()->assertJsonPath('message', 'Widget tidak ditemukan.');
    }

    // -------------------------------------------------------------------------
    // Komposisi karyawan: posisi per `to`, pembanding per `from`
    // -------------------------------------------------------------------------

    public function test_workforce_is_position_at_to_compared_with_from(): void
    {
        $total = $this->widget('workforce.kpi_total')['data'];

        // 31 Okt: Ani, Cici, Dina (Budi nonaktif 16 Okt); 1 Okt: berempat.
        $this->assertSame(3, $total['value']);
        $this->assertSame(4, $total['previous']);
        $this->assertSame(-25.0, (float) $total['change_percent']);
        $this->assertSame(
            ['Karyawan Tetap' => 1, 'Karyawan PKWT' => 0, 'Karyawan Casual' => 2, 'Karyawan Daily Worker' => 0],
            array_column($total['breakdown'], 'value', 'key'),
        );

        $summary = $this->widget('summary.workforce')['data'];
        $this->assertSame(3, $summary['value']);
        $this->assertSame('masuk 0 · keluar 1', $summary['note']);

        $gender = $this->widget('workforce.kpi_gender')['data'];
        $this->assertSame(33.3, (float) $gender['value']);   // 1 dari 3 perempuan
    }

    public function test_workforce_matrix_and_demography(): void
    {
        $matrix = $this->widget('workforce.matrix')['data'];
        $this->assertSame(['Bidang Tanaman', 'Tanpa Bidang'], array_column($matrix['rows'], 'label'));
        $this->assertSame([[0, 0, 1, 0], [1, 0, 1, 0]], $matrix['values']);
        $this->assertSame([1, 2], $matrix['row_totals']);
        $this->assertSame(3, $matrix['total']);

        $gender = $this->widget('workforce.demography_gender')['data'];
        $this->assertSame(['Laki-laki' => 2, 'Perempuan' => 1], array_column($gender['items'], 'value', 'key'));
        $this->assertSame(3, $gender['total']);
    }

    // -------------------------------------------------------------------------
    // Upah: akumulasi, sama dengan Daftar Upah
    // -------------------------------------------------------------------------

    public function test_payroll_widgets_equal_daftar_upah(): void
    {
        $net = $this->widget('payroll.kpi_net')['data'];
        $this->assertSame($this->payrollTotal('2026-10'), $net['value']);
        $this->assertSame(2_547_600 + 1_500_000, $net['value']);
        $this->assertSame($this->payrollTotal('2026-09'), $net['previous']);

        $this->assertSame($this->payrollTotal('2026-10'), $this->widget('summary.payroll')['data']['value']);
        $this->assertSame($this->payrollTotal('2026-10', 'deductions_total'), $this->widget('payroll.kpi_deductions')['data']['value']);

        $matrix = $this->widget('payroll.matrix')['data'];
        $this->assertSame(['Gunung Mas', 'Petugas Parkir', 'Tanpa Kelompok'], array_column($matrix['rows'], 'label'));
        $this->assertSame([2_547_600, 1_500_000, 0], $matrix['row_totals']);
        $this->assertSame(4_047_600, $matrix['total']);
    }

    public function test_payroll_accumulates_months_and_compares_with_previous_range(): void
    {
        $range = 'from=2026-09-01&to=2026-10-31';
        $net = $this->widget('payroll.kpi_net', $range)['data'];

        $this->assertSame($this->payrollTotal('2026-09') + $this->payrollTotal('2026-10'), $net['value']);
        $this->assertSame($this->payrollTotal('2026-07') + $this->payrollTotal('2026-08'), $net['previous']);

        $trend = $this->widget('payroll.trend', $range)['data'];
        $this->assertSame(['Sep 2026', 'Okt 2026'], array_column($trend['categories'], 'label'));
        $netSeries = collect($trend['series'])->firstWhere('key', 'net');
        $this->assertSame('line', $netSeries['kind']);
        $this->assertSame([$this->payrollTotal('2026-09'), $this->payrollTotal('2026-10')], $netSeries['values']);
    }

    // -------------------------------------------------------------------------
    // Filter
    // -------------------------------------------------------------------------

    public function test_each_filter_narrows_widgets(): void
    {
        $period = 'from=2026-10-01&to=2026-10-31';
        $netBy = fn (string $filter) => array_sum(collect($this->widget('payroll.by_group', "{$period}&{$filter}")['data']['series'])->firstWhere('key', 'net')['values']);
        $people = fn (string $filter) => $this->widget('workforce.matrix', "{$period}&{$filter}")['data']['total'];

        $this->assertSame(2_547_600, $netBy("filters[payroll_group][]={$this->mountain->id}"));
        $this->assertSame(1_500_000, $netBy('filters[employment_status][]=Karyawan Daily Worker'));
        $this->assertSame(1_500_000, $netBy('filters[division][]=tanpa'));
        $this->assertSame(4_047_600, $netBy("filters[payroll_group][]={$this->mountain->id}&filters[payroll_group][]={$this->parking->id}"));   // "atau"
        $this->assertSame(0, $netBy("filters[payroll_group][]={$this->mountain->id}&filters[employment_status][]=Karyawan Daily Worker"));   // "dan"

        $this->assertSame(1, $people("filters[placement_object][]={$this->teaBridge->id}"));
        $this->assertSame(1, $people("filters[segment][]={$this->teaBridge->category_id}"));
        $this->assertSame(1, $people("filters[sub_unit][]={$this->ani->assignments()->first()->sub_unit_id}"));
        $this->assertSame(1, $people("filters[cost_center][]={$this->ani->costCenterHistories()->first()->cost_center_id}"));
        $this->assertSame(1, $people('filters[employment_status][]=Karyawan Tetap'));

        $widget = $this->widget('workforce.matrix', "{$period}&filters[employment_status][]=Karyawan Tetap");
        $this->assertSame(['employment_status' => ['Karyawan Tetap']], $widget['filters']);
    }

    public function test_kpi_widgets_ignore_filters(): void
    {
        $filtered = $this->widget('workforce.kpi_total', 'from=2026-10-01&to=2026-10-31&filters[employment_status][]=Karyawan Tetap');

        $this->assertSame(3, $filtered['data']['value']);
        $this->assertSame([], $filtered['filters']);
    }

    public function test_group_by_changes_grouping(): void
    {
        $byDivision = $this->widget('payroll.by_group', 'from=2026-10-01&to=2026-10-31&group_by=division')['data'];

        $this->assertSame(['Bidang Tanaman', 'Tanpa Bidang'], array_column($byDivision['categories'], 'label'));
        $this->assertSame([2_547_600, 1_500_000], $byDivision['series'][0]['values']);
    }

    // -------------------------------------------------------------------------
    // Ringkasan
    // -------------------------------------------------------------------------

    public function test_attendance_and_overtime_summary(): void
    {
        $attendance = $this->widget('summary.attendance')['data'];
        // K 22 hari ÷ hari aktif (Ani 31 + Budi 15 + Cici 31 = 77).
        $this->assertSame(28.6, (float) $attendance['value']);
        $codes = array_column($attendance['breakdown'], 'value', 'key');
        $this->assertSame(22, $codes['K']);
        $this->assertSame(55, $codes['kosong']);

        $overtime = $this->widget('summary.overtime')['data'];
        $this->assertSame(2.0, (float) $overtime['value']);
        $this->assertSame(2.0, (float) array_column($overtime['breakdown'], 'value', 'key')['regular']);
    }

    public function test_actions_list(): void
    {
        $items = collect($this->widget('summary.actions')['data']['items'])->keyBy('key');

        // Per 31 Okt: Cici (aktif) tanpa skema, rekening, & cost center; Budi sudah nonaktif.
        $this->assertSame(1, $items['without_wage_scheme']['count']);
        $this->assertSame(1, $items['without_bank_account']['count']);
        $this->assertSame(1, $items['without_cost_center']['count']);
        $this->assertSame(0, $items['draft_activations']['count']);
        $this->assertSame(1, $items['unlocked_payroll']['count']);   // Oktober sudah lewat, belum dikunci
        $this->assertSame('/dashboard/upah/daftar-upah', $items['unlocked_payroll']['href']);
    }

    // -------------------------------------------------------------------------
    // Pilihan filter
    // -------------------------------------------------------------------------

    public function test_filter_options(): void
    {
        $data = $this->getJson('/api/v1/dashboard/filter-options')->assertOk()->json('data');

        $this->assertSame(['sub_units', 'divisions', 'segments', 'placement_objects', 'cost_centers', 'payroll_groups', 'employment_statuses'], array_keys($data));
        $this->assertSame(['Gunung Mas', 'Petugas Parkir'], array_column($data['payroll_groups'], 'name'));
        $this->assertSame(['id' => $this->teaBridge->id, 'name' => 'Tea Bridge', 'segment_id' => $this->teaBridge->category_id], $data['placement_objects'][0]);
        $this->assertSame(['id' => 'Karyawan Casual', 'name' => 'Casual'], $data['employment_statuses'][2]);
        $this->assertSame(['id', 'code', 'name'], array_keys($data['cost_centers'][0]));
    }
}
