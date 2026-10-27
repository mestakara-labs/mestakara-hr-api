<?php

namespace Tests\Feature\Employee;

use App\Models\Employee;
use App\Models\PlacementObject;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\SubUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * Pengujian ekspor Data Karyawan ke Excel (GET /employees/export).
 */
class EmployeeExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 10:00:00');
    }

    public function test_requires_login(): void
    {
        $this->getJson('/api/v1/employees/export')->assertUnauthorized();
    }

    public function test_rejects_unknown_active_status(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/employees/export?active_status=Pensiun')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.active_status.0', 'Status Kepegawaian harus Aktif, Belum Aktif, atau Nonaktif');
    }

    public function test_sends_xlsx_file_named_after_current_month(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $name = 'Database Karyawan PTPN I Regional 2 Unit Agrowisata - Oktober 2026.xlsx';

        $response = $this->get('/api/v1/employees/export')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$name.'"; filename*=UTF-8\'\''.rawurlencode($name));

        // Tanpa karyawan: tetap ada file berisi header saja.
        $sheet = $this->sheetOf($response);
        $this->assertSame('NO', $sheet->getCell('A1')->getValue());
        $this->assertSame('A3:DD3', $sheet->getAutoFilter()->getRange());
    }

    public function test_export_route_does_not_clash_with_employee_detail(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $employee = Employee::factory()->create();

        $this->getJson("/api/v1/employees/{$employee->id}")->assertOk()->assertJsonPath('data.id', $employee->id);
    }

    public function test_filters_by_active_status(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Employee::factory()->create(['name' => 'Ani', 'active_status' => 'Aktif']);
        Employee::factory()->create(['name' => 'Budi', 'active_status' => 'Belum Aktif']);
        Employee::factory()->create(['name' => 'Cici', 'active_status' => 'Nonaktif']);

        $this->assertSame(['ANI'], $this->namesIn($this->export('?active_status=Aktif')));
        $this->assertSame(['BUDI'], $this->namesIn($this->export('?active_status=Belum%20Aktif')));
        $this->assertSame(['CICI'], $this->namesIn($this->export('?active_status=Nonaktif')));
        $this->assertSame(['ANI', 'BUDI', 'CICI'], $this->namesIn($this->export('')));
    }

    public function test_writes_rows_sorted_by_brm_level_with_uppercase_text(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $subUnit = SubUnit::factory()->create(['name' => 'Agrowisata Gunung Mas', 'afdeling_code' => 'AFD03']);

        // Belum ditempatkan -> paling bawah, walaupun namanya "A".
        Employee::factory()->create(['name' => 'Aan Tanpa Jabatan']);

        $assistant = Employee::factory()->create(['name' => 'Budi Asisten']);
        PositionAssignment::factory()->for($assistant)->for($subUnit)
            ->for(Position::factory()->state(['title' => 'Asisten Personalia', 'brm_level' => 3]))->create();

        $manager = Employee::factory()->create([
            'name' => 'Zaki Manajer',
            'registration_number' => '01070577040088',
            'nik' => '08001244',
            'email' => 'Zaki.Manajer@Contoh.TEST',
            'birth_place' => 'bandung',
            'birth_date' => '1980-03-15',
        ]);
        $main = PositionAssignment::factory()->for($manager)->for($subUnit)
            ->for(Position::factory()->manager())->create(['start_date' => '2024-02-01']);
        $main->placementObjects()->attach(PlacementObject::factory()->create(['name' => 'Tea Bridge']));
        PositionAssignment::factory()->finished()->for($manager)->for($subUnit)
            ->for(Position::factory()->state(['title' => 'Asisten Tata Usaha']))->create();
        $manager->educations()->create(['level' => 'S1', 'institution' => 'Universitas Padjadjaran', 'major' => 'Agribisnis', 'graduation_year' => 2002, 'title_back' => 'S.P']);
        $manager->familyMembers()->create(['relationship' => 'Istri', 'name' => 'Siti', 'gender' => 'Perempuan', 'birth_date' => '1982-01-01', 'ktp_number' => '0201010101010001', 'dependent_status' => 'Ditanggung']);
        $manager->familyMembers()->create(['relationship' => 'Anak', 'name' => 'Raka', 'gender' => 'Laki-laki', 'birth_date' => '2010-05-05', 'dependent_status' => 'Ditanggung']);

        $sheet = $this->export('');

        $this->assertSame(['ZAKI MANAJER', 'BUDI ASISTEN', 'AAN TANPA JABATAN'], $this->namesIn($sheet));
        $this->assertSame(1, $sheet->getCell('A4')->getValue());
        $this->assertSame('II', $sheet->getCell('B4')->getValue());
        $this->assertSame('01070577040088', $sheet->getCell('E4')->getValue());   // teks, 0 di depan tetap ada
        $this->assertSame('08001244', $sheet->getCell('F4')->getValue());
        $this->assertSame('S.P', $sheet->getCell('I4')->getValue());
        $this->assertSame('AFD03', $sheet->getCell('J4')->getValue());
        $this->assertSame('TEA BRIDGE', $sheet->getCell('K4')->getValue());
        $this->assertSame('AGROWISATA GUNUNG MAS', $sheet->getCell('L4')->getValue());
        $this->assertSame('BANDUNG', $sheet->getCell('M4')->getValue());
        $this->assertSame('15/03/1980', $sheet->getCell('N4')->getFormattedValue());
        $this->assertSame(46, $sheet->getCell('O4')->getValue());
        $this->assertSame('L', $sheet->getCell('P4')->getValue());
        $this->assertSame('MANAGER', $sheet->getCell('Q4')->getValue());
        $this->assertSame('TETAP', $sheet->getCell('U4')->getValue());
        $this->assertSame('S1', $sheet->getCell('AD4')->getValue());
        $this->assertSame('15/03/2036', $sheet->getCell('AI4')->getFormattedValue());   // karpim: usia 56
        $this->assertSame('zaki.manajer@contoh.test', $sheet->getCell('AY4')->getValue());
        $this->assertSame('SITI', $sheet->getCell('BC4')->getValue());
        $this->assertSame('0201010101010001', $sheet->getCell('BE4')->getValue());
        $this->assertSame('RAKA', $sheet->getCell('BF4')->getValue());
        $this->assertSame('UNIVERSITAS PADJADJARAN', $sheet->getCell('CC4')->getValue());
        $this->assertSame('01/02/2024', $sheet->getCell('CK4')->getFormattedValue());
        $this->assertSame('ASISTEN TATA USAHA', $sheet->getCell('CL4')->getValue());
        $this->assertSame('1 TAHUN 11 BULAN', $sheet->getCell('CN4')->getValue());

        // Belum ditempatkan: kolom jabatan & pensiun kosong.
        $this->assertNull($sheet->getCell('Q6')->getValue());
        $this->assertNull($sheet->getCell('AI6')->getValue());
    }

    public function test_follows_the_reference_format(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Employee::factory()->count(2)->create();

        $sheet = $this->export('');

        $this->assertSame('Database Karyawan', $sheet->getTitle());
        $this->assertSame('Nama Karyawan', $sheet->getCell('G1')->getValue());   // satu sel G1:G2
        $this->assertNull($sheet->getCell('G2')->getValue());
        $this->assertSame('Riwayat  2 (dua) Jabatan Sebelum Menduduki Jabatan Saat ini (Nama Jabatan sesuai dengan SK)', $sheet->getCell('CO1')->getValue());
        $this->assertCount(72, $sheet->getMergeCells());
        foreach (['A1:A2', 'G1:G2', 'H1:H2', 'I1:I2', 'AF1:AN1', 'BC1:BQ1', 'CX1:DD1'] as $range) {
            $this->assertArrayHasKey($range, $sheet->getMergeCells());
        }

        $this->assertSame(26.0, $sheet->getColumnDimension('E')->getWidth());
        $this->assertSame(54.88671875, $sheet->getColumnDimension('Q')->getWidth());
        foreach (range('H', 'P') as $hidden) {
            $this->assertFalse($sheet->getColumnDimension($hidden)->getVisible(), $hidden);
        }
        $this->assertTrue($sheet->getColumnDimension('Q')->getVisible());

        $this->assertSame('215F9A', $sheet->getStyle('A1')->getFill()->getStartColor()->getRGB());
        $this->assertSame('00B050', $sheet->getStyle('E1')->getFill()->getStartColor()->getRGB());
        $this->assertSame('Tahoma', $sheet->getStyle('A1')->getFont()->getName());
        $this->assertSame('FFFFFF', $sheet->getStyle('A1')->getFont()->getColor()->getRGB());
        $this->assertSame('Tahoma', $sheet->getStyle('DD5')->getFont()->getName());
        $this->assertSame('thin', $sheet->getStyle('DD5')->getBorders()->getBottom()->getBorderStyle());
        $this->assertSame('dd/mm/yyyy;@', $sheet->getStyle('N4')->getNumberFormat()->getFormatCode());
        $this->assertSame('@', $sheet->getStyle('E4')->getNumberFormat()->getFormatCode());

        // Semua kolom tanggal rata tengah; kolom teks biasa tidak.
        foreach (['N', 'AI', 'BD', 'BG', 'CF', 'CK', 'CM', 'CV'] as $dateColumn) {
            $this->assertSame('center', $sheet->getStyle("{$dateColumn}4")->getAlignment()->getHorizontal(), $dateColumn);
        }
        $this->assertSame('general', $sheet->getStyle('Q4')->getAlignment()->getHorizontal());

        // Baris 3 = baris filter: kosong, abu-abu, ber-border; tombol filter Excel ada di baris ini.
        $this->assertNull($sheet->getCell('G3')->getValue());
        $this->assertSame('D9D9D9', $sheet->getStyle('A3')->getFill()->getStartColor()->getRGB());
        $this->assertSame('D9D9D9', $sheet->getStyle('DD3')->getFill()->getStartColor()->getRGB());
        $this->assertSame('thin', $sheet->getStyle('DD3')->getBorders()->getBottom()->getBorderStyle());

        $this->assertSame(30.0, $sheet->getRowDimension(1)->getRowHeight());
        $this->assertSame(54.0, $sheet->getRowDimension(2)->getRowHeight());
        $this->assertSame(20.0, $sheet->getRowDimension(3)->getRowHeight());
        $this->assertSame(30.0, $sheet->getRowDimension(5)->getRowHeight());
        $this->assertSame('A4', $sheet->getFreezePane());
        $this->assertSame('A3:DD5', $sheet->getAutoFilter()->getRange());
        $this->assertSame(55, $sheet->getSheetView()->getZoomScale());
    }

    public function test_commodity_is_tea_for_afdeling_gunung_mas(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $teaEstate = SubUnit::factory()->create(['name' => 'Afdeling Gunung Mas', 'afdeling_code' => 'AFD50']);
        $tourism = SubUnit::factory()->create(['name' => 'Gunung Mas', 'afdeling_code' => 'AFD03']);

        PositionAssignment::factory()->for(Employee::factory()->state(['name' => 'Ani Kebun']))->for($teaEstate)->create();
        PositionAssignment::factory()->for(Employee::factory()->state(['name' => 'Budi Wisata']))->for($tourism)->create();
        Employee::factory()->create(['name' => 'Cici Tanpa Jabatan']);

        $sheet = $this->export('');

        $this->assertSame(['ANI KEBUN', 'BUDI WISATA', 'CICI TANPA JABATAN'], $this->namesIn($sheet));
        $this->assertSame('TEH', $sheet->getCell('C4')->getValue());
        $this->assertSame('AGROWISATA', $sheet->getCell('C5')->getValue());
        $this->assertSame('AGROWISATA', $sheet->getCell('C6')->getValue());
    }

    /** Unduh file ekspor (dengan query, mis. "?active_status=Aktif") lalu buka sheet-nya. */
    private function export(string $query): Worksheet
    {
        return $this->sheetOf($this->get('/api/v1/employees/export'.$query)->assertOk());
    }

    private function sheetOf(TestResponse $response): Worksheet
    {
        $path = $response->baseResponse->getFile()->getPathname();
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        return $sheet;
    }

    /** @return list<string> isi kolom Nama Karyawan, urut baris */
    private function namesIn(Worksheet $sheet): array
    {
        $names = [];
        for ($row = 4; $row <= $sheet->getHighestDataRow('G'); $row++) {
            $names[] = $sheet->getCell("G{$row}")->getValue();
        }

        return $names;
    }
}
