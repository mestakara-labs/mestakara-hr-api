<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceImportType;
use App\Models\OvertimeEntry;
use App\Models\OvertimeSetting;
use App\Services\Employee\EmployeeExportWorkbook;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Menulis FILE EXCEL template LEMBUR REGULER / LEMBUR DW satu periode (tata letak: OvertimeLayout,
 * kolom identitas sama dengan template Daftar Hadir):
 *
 *   - sudah terisi lembur jenis itu yang tersimpan, ditambah baris kosong BERUMUS untuk lembur baru
 *   - identitas (nama, jabatan, status, sub unit, kode afd, objek, kelompok upah, cost center & uraiannya)
 *     = VLOOKUP No. Registrasi ke sheet tersembunyi "Data Karyawan"; Durasi = MOD(selesai − mulai; 1)
 *     (lembur lewat tengah malam terhitung benar). Catatan: baris kosong berumus ikut diurutkan, jadi saat
 *     diurutkan A–Z baris kosong berada di atas (keputusan pengguna)
 *   - semua sel baris data TIDAK dikunci, termasuk kolom abu-abu berumus: boleh diedit (isiannya tidak dibaca saat
 *     unggah; identitas selalu diambil dari No. Registrasi). Excel menolak urut & hapus baris yang berisi sel terkunci
 *   - sheet diproteksi (judul dikunci) dengan izin: filter, urut, sisip baris, sembunyikan baris, hapus baris
 *   - Tanggal divalidasi dalam periode (dd/mm/yyyy)
 *   - baris 4 = baris filter abu-abu (lihat TemplateFilterRow); dibekukan: judul (baris 1–4) & kolom sampai Jabatan
 */
class OvertimeTemplateWorkbook
{
    private const FONT = 'Tahoma';

    /** Latar kolom yang terisi otomatis (rumus). */
    private const FORMULA_FILL = 'F2F2F2';

    /**
     * @param  Collection<string, PeriodEmployee>  $employees  karyawan yang dihitung dalam periode
     * @param  Collection<int, OvertimeEntry>  $entries  lembur jenis itu yang tersimpan (dengan relasi employee)
     */
    public function write(AttendancePeriod $period, AttendanceImportType $type, Collection $employees, Collection $entries): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName(self::FONT)->setSize(10);
        $sheet = $spreadsheet->getActiveSheet()->setTitle(OvertimeLayout::sheetTitle($type));

        $first = OvertimeLayout::FIRST_DATA_ROW;
        $lastRow = $first + $entries->count() + OvertimeLayout::BLANK_ROWS - 1;
        $lastColumn = OvertimeLayout::lastLetter();

        $this->writeHeader($sheet, $period, $type, $lastColumn);
        TemplateFilterRow::apply($sheet, OvertimeLayout::FILTER_ROW, OvertimeLayout::HEADER_ROW, $lastColumn);
        $this->writeRows($sheet, $entries->values(), $lastRow);
        $this->styleBody($sheet, $period, $lastColumn, $lastRow);
        $this->writeDataSheet($spreadsheet, $employees);
        $this->writeListSheet($spreadsheet, $period, $type);
        $this->writeGuideSheet($spreadsheet, $type);

        $sheet->setAutoFilter('A'.OvertimeLayout::FILTER_ROW.":{$lastColumn}{$lastRow}");
        $sheet->freezePane(Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString(OvertimeLayout::LAST_FROZEN_COLUMN) + 1).$first);
        // Nilai false = TIDAK dilarang: filter, urut, ubah lebar kolom, sembunyikan / sisip / hapus baris.
        $sheet->getProtection()->setSheet(true)->setAutoFilter(false)->setSort(false)->setFormatColumns(false)
            ->setFormatRows(false)->setInsertRows(false)->setDeleteRows(false);
        $spreadsheet->setActiveSheetIndex(0);

        // Rumus TIDAK dihitung di server (ribuan VLOOKUP bisa memakan menit); Excel menghitungnya saat file dibuka.
        $path = tempnam(sys_get_temp_dir(), 'template-lembur-');
        (new Xlsx($spreadsheet))->setPreCalculateFormulas(false)->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function writeHeader(Worksheet $sheet, AttendancePeriod $period, AttendanceImportType $type, string $lastColumn): void
    {
        $title = mb_strtoupper(OvertimeLayout::sheetTitle($type)).' KARYAWAN CASUAL & DAILY WORKER — UNIT AGROWISATA — Periode: '.$period->label();
        $sheet->setCellValue('A'.OvertimeLayout::TITLE_ROW, $title);
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->getRowDimension(1)->setRowHeight(24);

        $header = OvertimeLayout::HEADER_ROW;
        $headerEnd = OvertimeLayout::HEADER_END_ROW;
        foreach (OvertimeLayout::COLUMNS as [$letter, $label, $width]) {
            $sheet->setCellValue("{$letter}{$header}", $label);
            $sheet->mergeCells("{$letter}{$header}:{$letter}{$headerEnd}");
            $sheet->getColumnDimension($letter)->setWidth($width);
        }
        $sheet->getStyle("A{$header}:{$lastColumn}{$headerEnd}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => EmployeeExportWorkbook::HEADER_BLUE]],
        ]);
    }

    /**
     * Baris lembur tersimpan + baris kosong cadangan, semuanya dengan rumus identitas & durasi.
     *
     * @param  Collection<int, OvertimeEntry>  $entries
     */
    private function writeRows(Worksheet $sheet, Collection $entries, int $lastRow): void
    {
        $registration = OvertimeLayout::letter('registration_number');
        $start = OvertimeLayout::letter('start_time');
        $end = OvertimeLayout::letter('end_time');
        $data = "'".OvertimeLayout::DATA_SHEET_TITLE."'!\$A:\$".Coordinate::stringFromColumnIndex(count($this->dataColumns()));

        for ($row = OvertimeLayout::FIRST_DATA_ROW; $row <= $lastRow; $row++) {
            $key = "{$registration}{$row}";

            $this->setFormula($sheet, OvertimeLayout::letter('no').$row, "=IF({$key}=\"\",\"\",ROW()-".(OvertimeLayout::FIRST_DATA_ROW - 1).')');
            foreach (OvertimeLayout::COLUMNS as $column => [$letter, , , $lookup]) {
                if ($lookup !== null) {
                    $notFound = $column === 'name' ? '"No. Registrasi tidak ditemukan"' : '""';
                    // {$key}&"" = No. Registrasi sebagai teks (angka yang diketik tetap ketemu).
                    $this->setFormula($sheet, "{$letter}{$row}", "=IF({$key}=\"\",\"\",IFERROR(VLOOKUP({$key}&\"\",{$data},{$lookup},FALSE),{$notFound}))");
                }
            }
            $this->setFormula($sheet, OvertimeLayout::letter('duration').$row, "=IF(OR({$start}{$row}=\"\",{$end}{$row}=\"\"),\"\",IFERROR(MOD({$end}{$row}-{$start}{$row},1),\"\"))");

            $entry = $entries->get($row - OvertimeLayout::FIRST_DATA_ROW);
            if ($entry === null) {
                continue;
            }
            if ($entry->employee->registration_number !== null) {
                $sheet->setCellValueExplicit($key, $entry->employee->registration_number, DataType::TYPE_STRING);
            }
            $sheet->setCellValue(OvertimeLayout::letter('date').$row, ExcelDate::PHPToExcel($entry->date));
            $sheet->setCellValue("{$start}{$row}", $this->timeFraction($entry->start_time));
            $sheet->setCellValue("{$end}{$row}", $this->timeFraction($entry->end_time));
            $sheet->setCellValueExplicit(OvertimeLayout::letter('description').$row, $entry->description, DataType::TYPE_STRING);
        }
    }

    private function styleBody(Worksheet $sheet, AttendancePeriod $period, string $lastColumn, int $lastRow): void
    {
        $first = OvertimeLayout::FIRST_DATA_ROW;
        $range = fn (string $key) => OvertimeLayout::letter($key).$first.':'.OvertimeLayout::letter($key).$lastRow;

        // Seluruh baris (sampai kolom paling kanan) tidak dikunci; kalau masih ada sel terkunci, Excel menolak hapus baris.
        $sheet->getStyle("{$first}:{$lastRow}")->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
        $sheet->getStyle("A{$first}:{$lastColumn}{$lastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'A6A6A6']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            // Tidak dikunci: Excel hanya bisa mengurutkan & menghapus baris yang semua selnya tidak dikunci.
            'protection' => ['locked' => Protection::PROTECTION_UNPROTECTED],
        ]);
        $sheet->getStyle($range('registration_number'))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle($range('date'))->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        foreach (['start_time', 'end_time', 'duration'] as $key) {
            $sheet->getStyle($range($key))->getNumberFormat()->setFormatCode('hh:mm');
        }
        foreach (['no', 'afdeling_code', 'date', 'start_time', 'end_time', 'duration'] as $key) {
            $sheet->getStyle($range($key))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        foreach (OvertimeLayout::COLUMNS as $key => [, , , $lookup]) {
            if ($lookup !== null || $key === 'duration') {
                $sheet->getStyle($range($key))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::FORMULA_FILL);
            }
        }

        $dateValidation = (new DataValidation)
            ->setType(DataValidation::TYPE_DATE)
            ->setOperator(DataValidation::OPERATOR_BETWEEN)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Tanggal di luar periode')
            ->setError('Isi tanggal dalam periode '.$period->label().' (dd/mm/yyyy).')
            ->setFormula1((string) ExcelDate::PHPToExcel($period->start()))
            ->setFormula2((string) ExcelDate::PHPToExcel($period->end()));
        $sheet->setDataValidation($range('date'), $dateValidation);
    }

    /**
     * Sheet tersembunyi "Data Karyawan": No. Registrasi + identitas sesuai urutan VLOOKUP di OvertimeLayout::COLUMNS.
     *
     * @param  Collection<string, PeriodEmployee>  $employees
     */
    private function writeDataSheet(Spreadsheet $spreadsheet, Collection $employees): void
    {
        $sheet = $spreadsheet->createSheet()->setTitle(OvertimeLayout::DATA_SHEET_TITLE);
        $sheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        foreach ($this->dataColumns() as $index => $title) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'1', $title);
        }

        $row = 2;
        foreach ($employees as $item) {
            if ($item->employee->registration_number === null) {
                continue;
            }
            $values = [
                $item->employee->registration_number,
                $item->employee->name,
                $item->positionTitle(),
                $item->employee->employment_status,
                $item->subUnit()['name'] ?? null,
                $item->subUnit()['afdeling_code'] ?? null,
                $item->placementObjectNames(),
                $item->payrollGroupName(),
                $item->costCenter?->costCenter->code,
                $item->costCenter?->costCenter->name,
            ];
            foreach ($values as $index => $value) {
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($index + 1).$row, (string) $value, DataType::TYPE_STRING);
            }
            $row++;
        }
    }

    /** @return list<string> judul kolom sheet "Data Karyawan" (kolom 1 = No. Registrasi, lalu sesuai nomor VLOOKUP) */
    private function dataColumns(): array
    {
        $titles = [1 => 'No. Registrasi'];
        foreach (OvertimeLayout::COLUMNS as [, $label, , $lookup]) {
            if ($lookup !== null) {
                $titles[$lookup] = rtrim($label, '*');
            }
        }
        ksort($titles);

        return array_values($titles);
    }

    private function writeListSheet(Spreadsheet $spreadsheet, AttendancePeriod $period, AttendanceImportType $type): void
    {
        $sheet = $spreadsheet->createSheet()->setTitle(OvertimeLayout::LIST_SHEET_TITLE);
        $sheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $sheet->setCellValue('A1', 'Periode');
        $sheet->setCellValueExplicit(OvertimeLayout::PERIOD_CELL, $period->period, DataType::TYPE_STRING);
        $sheet->setCellValue('A2', 'Jenis');
        $sheet->setCellValueExplicit(OvertimeLayout::TYPE_CELL, $type->value, DataType::TYPE_STRING);
    }

    private function writeGuideSheet(Spreadsheet $spreadsheet, AttendanceImportType $type): void
    {
        $settings = OvertimeSetting::current();
        $sheet = $spreadsheet->createSheet(1)->setTitle(OvertimeLayout::GUIDE_SHEET_TITLE);
        $sheet->getColumnDimension('A')->setWidth(110);
        $lines = [
            'PETUNJUK PENGISIAN '.mb_strtoupper(OvertimeLayout::sheetTitle($type)),
            '',
            '1. Satu baris = satu karyawan, satu tanggal, satu rentang jam. Boleh lebih dari satu baris per tanggal asal jamnya tidak tumpang tindih.',
            '2. Isi No. Registrasi (karyawan Casual & DW aktif yang punya No. Registrasi). Kolom bertanda * (nama, jabatan, status, sub unit,',
            '   kode afd, objek, kelompok upah, cost center, uraian cost center, durasi) terisi otomatis; boleh diubah, tetapi tidak dibaca saat unggah.',
            '   Baris boleh disisipkan, disembunyikan, atau dihapus (klik kanan nomor baris).',
            '3. Tanggal format DD/MM/YYYY dalam periode. Jam format HH:MM (17:00 atau 17.00). Jam selesai lebih kecil dari jam mulai = lewat tengah malam.',
            '4. Uraian Pekerjaan wajib diisi.',
            '5. Gunakan baris abu-abu (baris 4) untuk filter & urut.',
            '6. Mengunggah ulang MENGGANTIKAN seluruh lembur jenis ini hasil unggahan sebelumnya pada periode ini.',
            '   Lembur dari aplikasi mobile & input manual tidak ditimpa; baris yang jamnya bertabrakan dengannya SALAH dan menolak seluruh unggahan.',
            '7. Unggah daftar hadir lebih dulu. Lembur pada tanggal berkode I, S, M, atau C = salah; tanggal berkode L atau kosong = peringatan.',
            "8. Total lembur (reguler + DW) lebih dari {$settings->max_hours_per_day} jam per hari atau {$settings->max_hours_per_week} jam per minggu (Senin–Minggu) = peringatan.",
        ];
        foreach ($lines as $index => $line) {
            $sheet->setCellValue('A'.($index + 1), $line);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
    }

    /** Tulis rumus langsung sebagai rumus (lebih cepat daripada setCellValue yang menebak jenis isinya). */
    private function setFormula(Worksheet $sheet, string $cell, string $formula): void
    {
        $sheet->setCellValueExplicit($cell, $formula, DataType::TYPE_FORMULA);
    }

    /** "17:30:00" -> pecahan hari Excel (0,7291…). */
    private function timeFraction(string $time): float
    {
        return ((int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2)) / 1440;
    }
}
