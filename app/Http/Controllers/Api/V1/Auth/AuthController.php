<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\Auth\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Login, data user login, dan logout.
 *
 *   POST /api/v1/login   { login, password }  -> { token, user }
 *   GET  /api/v1/me                           -> user
 *   POST /api/v1/logout                       -> null
 *
 * Token dikirim front end di header "Authorization: Bearer <token>".
 */
class AuthController extends Controller
{
    /**
     * Login
     *
     * Masuk dengan NIK atau email + kata sandi. Simpan `data.token` dan kirim di header
     * `Authorization: Bearer <token>` untuk semua endpoint lain. Token berlaku 8 jam.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::findByLogin($request->string('login'));

        // Pesan dibuat sama untuk "user tidak ada" dan "sandi salah", supaya tidak membocorkan akun yang terdaftar.
        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            return ApiResponse::error('NIK/Email atau kata sandi salah.', 401);
        }

        if (! $user->is_active) {
            return ApiResponse::error('Akun ini sudah dinonaktifkan. Hubungi Admin SDM.', 403);
        }

        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('dashboard')->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'user' => UserResource::make($user),
        ], 'Login berhasil.');
    }

    /**
     * Data user login
     *
     * Dipakai front end untuk memeriksa apakah token masih berlaku.
     */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(UserResource::make($request->user()), 'Data user login.');
    }

    /**
     * Logout
     *
     * Mencabut token yang sedang dipakai. Login di perangkat lain tetap berjalan.
     */
    public function logout(Request $request): JsonResponse
    {
        // Hanya token yang sedang dipakai yang dicabut; login di perangkat lain tetap berjalan.
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Logout berhasil.');
    }
}
