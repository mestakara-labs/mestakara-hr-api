<?php

namespace App\Providers;

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
        //
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
