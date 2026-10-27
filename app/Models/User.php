<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Akun login dashboard (tabel users).
 *
 * Login memakai NIK atau email + kata sandi; token dibuat oleh Laravel Sanctum.
 */
#[Fillable(['employee_id', 'nik', 'name', 'email', 'password', 'role', 'is_active', 'deactivated_at', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Cari akun dari isian "NIK atau Email" di halaman login.
     * Email dibandingkan tanpa membedakan huruf besar/kecil.
     */
    public static function findByLogin(string $login): ?self
    {
        return self::query()
            ->where('nik', $login)
            ->orWhereRaw('LOWER(email) = ?', [mb_strtolower($login)])
            ->first();
    }
}
