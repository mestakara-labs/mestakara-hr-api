<?php

namespace App\Services\Attendance;

use App\Services\Employee\EmployeeExportWorkbook;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * BARIS FILTER (baris 4, abu-abu) template Daftar Hadir / Lembur Reguler / Lembur DW: tempat tombol filter & urut Excel.
 *
 * Baris ini BERISI judul kolom (salinan judul di atasnya). Kalau kosong, tombol urut cepat Excel (Data > A–Z) menganggapnya
 * baris data: satu baris karyawan pindah ke baris 4 dan tidak terbaca saat diunggah (data mulai baris 5).
 *
 * Sheet template diproteksi (judul dikunci). Excel MENOLAK filter & urut pada sheet terproteksi kalau baris judul
 * filternya terkunci, jadi baris ini sengaja TIDAK dikunci. Supaya tetap tidak bisa diketik, baris ini diberi
 * validasi data yang menolak setiap isian. Validasi tidak menghalangi filter & urut.
 */
class TemplateFilterRow
{
    /** @param  int  $headerRow  baris judul kolom yang disalin ke baris filter */
    public static function apply(Worksheet $sheet, int $row, int $headerRow, string $lastColumn): void
    {
        $range = "A{$row}:{$lastColumn}{$row}";

        for ($column = 1; $column <= Coordinate::columnIndexFromString($lastColumn); $column++) {
            $title = rtrim((string) $sheet->getCell([$column, $headerRow])->getValue(), '*');
            $sheet->setCellValueExplicit([$column, $row], $title, DataType::TYPE_STRING);
        }

        $sheet->getStyle($range)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => EmployeeExportWorkbook::FILTER_GREY]],
            'font' => ['size' => 8, 'color' => ['rgb' => '595959']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'protection' => ['locked' => Protection::PROTECTION_UNPROTECTED],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(20);

        $noTyping = (new DataValidation)
            ->setType(DataValidation::TYPE_CUSTOM)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(false)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Baris filter')
            ->setError('Baris abu-abu ini hanya untuk filter & urut. Isi data mulai baris di bawahnya.')
            ->setFormula1('FALSE');
        $sheet->setDataValidation($range, $noTyping);
    }
}
