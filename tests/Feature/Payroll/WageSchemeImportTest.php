<?php

namespace Tests\Feature\Payroll;

use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeCostCenter;
use App\Models\EmployeeWageScheme;
use App\Models\PayrollGroup;
use App\Models\User;
use App\Services\Payroll\WageSchemeTemplateColumns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Pengujian template & impor Skema Upah (GET /wage-schemes/template, POST /wage-schemes/import[/preview]).
 * Hari ini dikunci 20 Oktober 2026.
 */
class WageSchemeImportTest extends TestCase
{
    use RefreshDatabase;

    private Employee $casual;

    private Employee $permanent;

    private PayrollGroup $group;

    private CostCenter $costCenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 10:00:00');
        Sanctum::actingAs(User::factory()->create());

        $this->group = PayrollGroup::factory()->create(['name' => 'Gunung Mas']);
        $this->costCenter = CostCenter::factory()->create(['code' => '4A44AGR007']);
        CostCenter::factory()->create(['code' => '4A44AGR001']);

        $this->casual = Employee::factory()->active()->create([
            'name' => 'Ani', 'employment_status' => 'Karyawan Casual', 'nik' => null, 'registration_number' => '7530',
        ]);
        $this->permanent = Employee::factory()->active()->create(['name' => 'Budi', 'registration_number' => '0101138304']);

        EmployeeWageScheme::create([
            'employee_id' => $this->casual->id, 'wage_type' => 'Harian', 'base_salary' => 2_500_000, 'working_days' => 25,
            'has_meal_allowance' => true, 'meal_allowance_per_day' => 15_000, 'payroll_group_id' => $this->group->id,
            'effective_date' => '2026-01-01',
        ]);
        $this->casual->bankAccounts()->create([
            'bank_name' => 'BRI', 'account_number' => '012301000123567', 'account_holder' => 'ANI', 'effective_date' => '2026-01-01',
        ]);
    }

    // -------------------------------------------------------------------------
    // Template
    // -------------------------------------------------------------------------

    public function test_template_contains_all_active_employees_with_current_values_and_dropdowns(): void
    {
        $name = 'Template Skema Upah - Oktober 2026.xlsx';
        $response = $this->get('/api/v1/wage-schemes/template?period=2026-10')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$name.'"; filename*=UTF-8\'\''.rawurlencode($name));

        $spreadsheet = $this->spreadsheetOf($response);
        $this->assertSame(['Skema Upah', 'Petunjuk', 'Daftar'], $spreadsheet->getSheetNames());
        $sheet = $spreadsheet->getSheetByName('Skema Upah');

        foreach (WageSchemeTemplateColumns::COLUMNS as $column) {
            $this->assertSame($column['header'], $sheet->getCell($column['letter'].'1')->getValue());
        }
        $this->assertFalse($sheet->getColumnDimension('A')->getVisible());   // ID Sistem tersembunyi
        $this->assertTrue($sheet->getProtection()->getSheet());

        // Baris 2 = Ani (Casual), baris 3 = Budi (Tetap), urut nama.
        $this->assertSame($this->casual->id, $sheet->getCell('A2')->getValue());
        $this->assertSame('7530', $sheet->getCell('B2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('P2')->getDataType());   // No. Rekening sebagai teks
        $this->assertSame('012301000123567', $sheet->getCell('P2')->getValue());
        $this->assertSame('Harian', $sheet->getCell('F2')->getValue());
        $this->assertSame(2_500_000, $sheet->getCell('G2')->getValue());
        $this->assertSame('Ya', $sheet->getCell('L2')->getValue());
        $this->assertSame('Gunung Mas', $sheet->getCell('N2')->getValue());
        $this->assertSame('01/10/2026', $sheet->getCell('S2')->getFormattedValue());   // tanggal 1 bulan periode
        $this->assertSame('Karyawan Tetap', $sheet->getCell('D3')->getValue());
        $this->assertNull($sheet->getCell('F3')->getValue());
        $this->assertTrue($sheet->getStyle('F3')->getProtection()->getLocked() === 'protected');   // skema dikunci untuk Tetap
        $this->assertSame('unprotected', $sheet->getStyle('F2')->getProtection()->getLocked());

        // Dropdown dari sheet "Daftar".
        $validations = $sheet->getDataValidationCollection();
        $this->assertSame("'Daftar'!\$A\$2:\$A\$3", $validations['F2:F3']->getFormula1());   // Jenis Upah
        $this->assertSame("'Daftar'!\$B\$2:\$B\$3", $validations['I2:I3']->getFormula1());   // Ya/Tidak
        $this->assertSame("'Daftar'!\$C\$2:\$C\$2", $validations['N2:N3']->getFormula1());   // Kelompok
        $this->assertSame("'Daftar'!\$D\$2:\$D\$3", $validations['R2:R3']->getFormula1());   // Cost Center
        $list = $spreadsheet->getSheetByName('Daftar');
        $this->assertSame(['Harian', 'Flat Bulanan'], [$list->getCell('A2')->getValue(), $list->getCell('A3')->getValue()]);
    }

    public function test_unchanged_template_is_previewed_as_unchanged_and_saves_nothing(): void
    {
        $path = $this->savedTemplate();

        $this->postJson('/api/v1/wage-schemes/import/preview', ['file' => $this->upload($path)])
            ->assertOk()
            ->assertJsonPath('data.summary', ['total_rows' => 2, 'changed' => 0, 'unchanged' => 2, 'invalid' => 0])
            ->assertJsonPath('data.rows.0.status', 'tidak berubah');

        $this->postJson('/api/v1/wage-schemes/import', ['file' => $this->upload($path)])
            ->assertOk()
            ->assertJsonPath('data.created', ['wage_schemes' => 0, 'bank_accounts' => 0, 'cost_centers' => 0]);
    }

    // -------------------------------------------------------------------------
    // Pratinjau: semua jenis kesalahan
    // -------------------------------------------------------------------------

    public function test_preview_reports_every_kind_of_error_per_column(): void
    {
        $file = $this->file([
            ['registration_number' => '9999', 'effective_date' => '01/10/2026'],                                                   // 2
            ['employee_id' => $this->permanent->id, 'wage_type' => 'Harian', 'base_salary' => 3_000_000, 'effective_date' => '01/10/2026'], // 3
            ['employee_id' => $this->casual->id, 'wage_type' => 'Mingguan', 'base_salary' => 0, 'working_days' => 40,
                'has_meal_allowance' => 'Ya', 'payroll_group' => 'Kelompok X', 'effective_date' => '01/10/2026'],                    // 4
            ['employee_id' => $this->casual->id, 'bank_name' => 'BRI', 'account_number' => '0123-45', 'account_holder' => 'ANI',
                'cost_center_code' => 'XXX', 'effective_date' => '31/02/2026'],                                                     // 5
            ['registration_number' => '0101138304', 'bank_name' => 'BNI', 'effective_date' => null],                                 // 6
        ]);

        $rows = $this->postJson('/api/v1/wage-schemes/import/preview', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.summary.invalid', 5)
            ->json('data.rows');

        $this->assertSame(['No. Registrasi' => 'No. Registrasi 9999 tidak ditemukan'], $this->errorsOf($rows[0]));
        $this->assertSame(['Jenis Upah' => 'Skema upah hanya untuk karyawan Casual & Daily Worker'], $this->errorsOf($rows[1]));
        $this->assertSame([
            'Kelompok Daftar Upah' => 'Kelompok Daftar Upah "Kelompok X" tidak dikenal',
            'Jenis Upah' => 'Jenis Upah harus Harian atau Flat Bulanan',
            'Gaji Pokok' => 'Gaji Pokok harus lebih dari 0',
            'HKE' => 'HKE harus 1–31',
            'Uang Makan per Hari' => 'Uang Makan per Hari wajib diisi kalau Diberi Uang Makan = Ya',
        ], $this->errorsOf($rows[2]));
        $this->assertSame([
            'Berlaku Mulai' => 'Berlaku Mulai harus berupa tanggal (DD/MM/YYYY)',
            'No. Rekening' => 'No. Rekening hanya boleh berisi angka',
            'Kode Cost Center' => 'Kode Cost Center "XXX" tidak dikenal',
            'No. Registrasi' => 'Baris kembar: karyawan ini sudah ada di baris 4',
        ], $this->errorsOf($rows[3]));
        $this->assertSame([
            'No. Rekening' => 'No. Rekening wajib diisi (rekening harus lengkap)',
            'Atas Nama' => 'Atas Nama wajib diisi (rekening harus lengkap)',
            'No. Registrasi' => 'Baris kembar: karyawan ini sudah ada di baris 3',   // Budi lewat ID Sistem & No. Registrasi
        ], $this->errorsOf($rows[4]));
    }

    public function test_preview_requires_effective_date_only_for_changed_rows(): void
    {
        $file = $this->file([
            ['employee_id' => $this->casual->id, 'cost_center_code' => '4A44AGR007', 'effective_date' => null],
            ['employee_id' => $this->permanent->id, 'effective_date' => null, 'notes' => 'tidak ada perubahan'],
        ]);

        $rows = $this->postJson('/api/v1/wage-schemes/import/preview', ['file' => $file])->assertOk()->json('data.rows');

        $this->assertSame(['Berlaku Mulai' => 'Berlaku Mulai wajib diisi karena ada data yang berubah'], $this->errorsOf($rows[0]));
        $this->assertSame('tidak berubah', $rows[1]['status']);
    }

    public function test_rejects_a_file_that_is_not_the_template(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Sheet1')->setCellValue('A1', 'Nama');

        $this->postJson('/api/v1/wage-schemes/import/preview', ['file' => $this->upload($this->save($spreadsheet))])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'File bukan template skema upah. Unduh template terbaru, isi, lalu unggah kembali tanpa mengubah judul kolom.');
    }

    // -------------------------------------------------------------------------
    // Simpan
    // -------------------------------------------------------------------------

    public function test_import_is_rejected_when_any_row_is_invalid(): void
    {
        $file = $this->file([
            ['employee_id' => $this->casual->id, 'cost_center_code' => '4A44AGR007', 'effective_date' => '01/10/2026'],
            ['registration_number' => '9999', 'effective_date' => '01/10/2026'],
        ]);

        $this->postJson('/api/v1/wage-schemes/import', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Masih ada 1 baris yang salah. Perbaiki file lalu unggah kembali.')
            ->assertJsonPath('errors', ['rows.3' => ['Baris 3 – No. Registrasi: No. Registrasi 9999 tidak ditemukan']]);

        $this->assertSame(0, EmployeeCostCenter::count());
    }

    public function test_import_adds_only_the_histories_that_changed(): void
    {
        $file = $this->file([
            // Ani: gaji berubah, kelompok dikosongkan, rekening sama, cost center baru.
            ['employee_id' => $this->casual->id, 'wage_type' => 'harian', 'base_salary' => '2.700.000', 'working_days' => 25,
                'is_capped' => 'Tidak', 'has_meal_allowance' => 'Ya', 'meal_allowance_per_day' => 15_000, 'payroll_group' => null,
                'bank_name' => 'BRI', 'account_number' => '012301000123567', 'account_holder' => 'ANI',
                'cost_center_code' => '4a44agr007', 'effective_date' => '01/10/2026', 'notes' => 'Kenaikan UMK'],
            // Budi (Tetap): hanya rekening baru, dicari dari No. Registrasi.
            ['registration_number' => '0101138304', 'bank_name' => 'Mandiri', 'account_number' => '1310012345678',
                'account_holder' => 'BUDI', 'effective_date' => '2026-10-01'],
        ]);

        $this->postJson('/api/v1/wage-schemes/import', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.employee_count', 2)
            ->assertJsonPath('data.created', ['wage_schemes' => 1, 'bank_accounts' => 1, 'cost_centers' => 1]);

        $scheme = $this->casual->wageSchemes()->where('effective_date', '>=', '2026-10-01')->sole();
        $this->assertSame(2_700_000, $scheme->base_salary);
        $this->assertNull($scheme->payroll_group_id);
        $this->assertSame('Kenaikan UMK', $scheme->notes);
        $this->assertSame(1, $this->casual->bankAccounts()->count());   // rekening sama: tidak ditambah
        $this->assertSame($this->costCenter->id, $this->casual->costCenterHistories()->sole()->cost_center_id);
        $this->assertSame('1310012345678', EmployeeBankAccount::where('employee_id', $this->permanent->id)->sole()->account_number);
        $this->assertSame(2, EmployeeWageScheme::count());   // catatan lama tetap ada
    }

    // -------------------------------------------------------------------------
    // Bantuan
    // -------------------------------------------------------------------------

    /** File impor buatan: judul kolom template + baris [kunci kolom => nilai]. */
    private function file(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet()->setTitle(WageSchemeTemplateColumns::SHEET_TITLE);

        foreach (WageSchemeTemplateColumns::COLUMNS as $column) {
            $sheet->setCellValue($column['letter'].'1', $column['header']);
        }
        foreach ($rows as $index => $row) {
            foreach ($row as $key => $value) {
                if ($value !== null) {
                    $cell = WageSchemeTemplateColumns::letter($key).($index + 2);
                    is_string($value) ? $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING) : $sheet->setCellValue($cell, $value);
                }
            }
        }

        return $this->upload($this->save($spreadsheet));
    }

    /** Template asli dari endpoint, disimpan ke file sementara. */
    private function savedTemplate(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $this->get('/api/v1/wage-schemes/template')->assertOk()->streamedContent());

        return $path;
    }

    private function save(Spreadsheet $spreadsheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function upload(string $path): UploadedFile
    {
        return new UploadedFile($path, 'skema-upah.xlsx', null, null, true);
    }

    private function spreadsheetOf(TestResponse $response): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        return IOFactory::load($path);
    }

    /** errors baris pratinjau dalam bentuk [kolom => pesan]. */
    private function errorsOf(array $row): array
    {
        return array_column($row['errors'], 'message', 'column');
    }
}
