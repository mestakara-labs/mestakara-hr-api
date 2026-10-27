<?php

namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Resources\Organization\PositionResource;
use App\Models\OrganizationDecree;
use App\Models\Position;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Data bagan Struktur Organisasi.
 *
 *   GET /organization  ->  { decree_number, decree_date, positions[] }
 */
class OrganizationController extends Controller
{
    /**
     * Struktur organisasi
     *
     * SK struktur organisasi yang berlaku + semua jabatan beserta pemegangnya.
     * Kalau belum ada SK yang berlaku, decree_number dan decree_date berisi "".
     */
    public function __invoke(): JsonResponse
    {
        $decree = OrganizationDecree::active();
        $positions = Position::query()->with(PositionResource::RELATIONS)->orderBy('brm_level')->orderBy('id')->get();

        return ApiResponse::success([
            'decree_number' => $decree?->decree_number ?? '',
            'decree_date' => $decree?->decree_date->toDateString() ?? '',
            'positions' => PositionResource::collection($positions),
        ], 'Struktur organisasi.');
    }
}
