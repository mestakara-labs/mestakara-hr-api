<?php

use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Semua endpoint API diawali /api/v1 (sesuai BACKEND_URL di front end).
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($isApi);

        /*
         * Semua error API diubah ke bentuk standar { success, message, data, errors? }
         * (lihat App\Support\ApiResponse), supaya front end cukup membaca satu bentuk.
         */

        // 422 — isian tidak valid / aturan bisnis dilanggar. errors = { isian: [pesan] }.
        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiResponse::error('Data yang dikirim belum benar.', 422, $e->errors());
            }
        });

        // 401 — belum login atau token sudah tidak berlaku.
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiResponse::error('Sesi login tidak valid. Silakan login kembali.', 401);
            }
        });

        // 404 — alamat tidak ada, data (id) tidak ditemukan, atau abort(404, 'pesan') dari kode.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                $message = match (true) {
                    $request->route() === null => 'Alamat API tidak ditemukan.',
                    $e->getPrevious() instanceof ModelNotFoundException => 'Data tidak ditemukan.',
                    default => $e->getMessage() ?: 'Data tidak ditemukan.',
                };

                return ApiResponse::error($message, 404);
            }
        });

        // Error HTTP lain yang sengaja dilempar dengan abort(409, 'pesan'), dst.
        $exceptions->render(function (HttpException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiResponse::error($e->getMessage() ?: 'Permintaan tidak dapat diproses.', $e->getStatusCode());
            }
        });
    })->create();
