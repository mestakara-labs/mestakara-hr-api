<?php

namespace App\Providers;

use App\Services\Dashboard\AttendanceMonths;
use App\Services\Dashboard\PayrollMonths;
use App\Services\Dashboard\WorkforceFacts;
use App\Support\ApiDocumentation;
use Dedoc\Scramble\Scramble;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Dashboard: data karyawan & Daftar Upah per bulan dihitung SEKALI per permintaan, dipakai bersama semua widget.
        foreach ([WorkforceFacts::class, PayrollMonths::class, AttendanceMonths::class] as $service) {
            $this->onePerRequest($service);
        }
    }

    /**
     * Satu instance per permintaan HTTP, disimpan di objek request. Bukan scoped binding, karena instance scoped
     * tetap hidup untuk permintaan berikutnya di proses yang sama (pengujian, server yang tidak di-restart per request)
     * sehingga hasil hitungan lama ikut terpakai.
     */
    private function onePerRequest(string $service): void
    {
        $this->app->bind($service, function (Application $app) use ($service) {
            $remembered = $app->make('request')->attributes;
            if (! $remembered->has($service)) {
                $remembered->set($service, $app->build($service));
            }

            return $remembered->get($service);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Dokumentasi API (/docs/api): respons error memakai bentuk standar aplikasi.
        Scramble::configure()->withDocumentTransformers(
            fn ($openApi) => ApiDocumentation::useStandardErrorResponses($openApi),
        );
    }
}
