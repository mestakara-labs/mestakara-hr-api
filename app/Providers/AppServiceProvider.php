<?php

namespace App\Providers;

use App\Services\Dashboard\AttendanceMonths;
use App\Services\Dashboard\PayrollMonths;
use App\Services\Dashboard\WorkforceFacts;
use App\Support\ApiDocumentation;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Dashboard: data karyawan & Daftar Upah per bulan dihitung SEKALI per request, dipakai bersama semua widget.
        $this->app->scoped(WorkforceFacts::class);
        $this->app->scoped(PayrollMonths::class);
        $this->app->scoped(AttendanceMonths::class);
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
