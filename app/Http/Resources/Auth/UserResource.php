<?php

namespace App\Http\Resources\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Data user login, sesuai UserResource di front end (types/api/user.ts).
 *
 *   { id, nik, name, email, position, unit_name }
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nik' => $this->nik,
            'name' => $this->name,
            'email' => $this->email,
            // Sementara memakai nama peran. Nanti bisa diganti jabatan karyawan pemilik akun.
            'position' => $this->role->value,
            'unit_name' => config('hr.unit_name'),
        ];
    }
}
