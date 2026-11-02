<?php

/*
|--------------------------------------------------------------------------
| PERINTAH ARTISAN & JADWAL HARIAN
|--------------------------------------------------------------------------
|
| Jadwal di bawah dijalankan oleh scheduler Laravel. Di server, cukup pasang satu cron:
|
|   * * * * * cd /path/ke/api && php artisan schedule:run >> /dev/null 2>&1
|
| Lihat daftar jadwal dengan: php artisan schedule:list
|
*/

use App\Services\EmployeeStatusService;
use App\Services\Payroll\PdfRenderer;
use App\Services\Schedule\SchedulePeriodService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
 * Perbarui status kepegawaian semua karyawan (Belum Aktif / Aktif / Nonaktif) dan akun loginnya,
 * untuk usulan Penambahan / Penonaktifan yang TMT-nya baru tiba hari ini.
 */
Artisan::command('hr:refresh-employee-status', function (EmployeeStatusService $statusService) {
    $changed = $statusService->refreshAll();
    $this->info("Status kepegawaian diperbarui. {$changed} karyawan berubah status.");
})->purpose('Perbarui status kepegawaian karyawan dari riwayat Penambahan & Penonaktifan');

Schedule::command('hr:refresh-employee-status')->dailyAt('00:05')->withoutOverlapping();

/*
 * Buat jadwal kerja Draf BULAN DEPAN untuk semua regu yang belum punya (dibuat oleh "Sistem").
 * Admin SDM lalu memeriksa, mengubah sel bila perlu, dan menerbitkannya.
 */
Artisan::command('hr:generate-schedules', function (SchedulePeriodService $periodService) {
    $created = $periodService->generateNextMonth();
    $this->info("{$created} jadwal Draf bulan depan dibuat.");
})->purpose('Buat jadwal kerja Draf bulan depan untuk semua regu kerja');

Schedule::command('hr:generate-schedules')->monthlyOn(20, '01:00')->withoutOverlapping();

/*
 * Ubah SATU file HTML menjadi PDF (dompdf). Dijalankan PdfRenderer sebagai proses terpisah supaya bagian-bagian PDF
 * ekspor yang besar dirender bersamaan (lihat config hr.pdf_processes); tidak untuk dipanggil manual.
 */
Artisan::command('hr:render-pdf {html : File HTML sumber} {output : File PDF hasil} {orientation=portrait} {paper=A4}', function (PdfRenderer $pdf) {
    $html = file_get_contents($this->argument('html'));
    if ($html === false) {
        $this->error('File HTML tidak bisa dibaca: '.$this->argument('html'));

        return 1;
    }
    file_put_contents($this->argument('output'), $pdf->render($html, $this->argument('orientation'), $this->argument('paper')));

    return 0;
})->purpose('Render satu file HTML menjadi PDF (proses render paralel ekspor PDF)');
