<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Pembentuk respons JSON standar untuk SEMUA endpoint API.
 *
 * Front end (types/api/response.ts) selalu menerima bentuk yang sama:
 *
 *   {
 *     "success": true,
 *     "message": "Data bidang berhasil disimpan.",
 *     "data":    { ... },
 *     "errors":  { "name": ["Nama wajib diisi."] }   <- hanya saat error validasi (422)
 *   }
 *
 * Contoh pemakaian di controller:
 *
 *   return ApiResponse::success(DivisionResource::make($division), 'Bidang berhasil ditambahkan.', 201);
 *   return ApiResponse::error('Bidang tidak ditemukan.', 404);
 */
class ApiResponse
{
    /** Respons berhasil. `$data` boleh berupa Resource, array, atau null. */
    public static function success(mixed $data = null, string $message = 'Berhasil.', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /** Respons gagal. `$errors` diisi pesan per isian: ['name' => ['Nama wajib diisi.']]. */
    public static function error(string $message, int $status = 400, ?array $errors = null): JsonResponse
    {
        $body = [
            'success' => false,
            'message' => $message,
            'data' => null,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status);
    }
}
