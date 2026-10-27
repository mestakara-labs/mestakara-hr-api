<?php

namespace App\Http\Controllers\Api\V1\Assignment;

use App\Http\Controllers\Controller;
use App\Http\Resources\Assignment\AssignmentResource;
use App\Models\PositionAssignment;
use App\Services\FileStorage;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Jabatan Karyawan (penugasan jabatan) — HANYA DIBACA di sini.
 * Penugasan dibuat & diakhiri lewat Mutasi & Penempatan, Penambahan, dan Penonaktifan.
 *
 *   GET /position-assignments                    daftar (?employee_id= untuk satu karyawan)
 *   GET /position-assignments/{id}               detail
 *   GET /position-assignments/{id}/decree-file   file dokumen dasar (PDF/gambar)
 */
class PositionAssignmentController extends Controller
{
    /**
     * Daftar penugasan jabatan
     *
     * Terbaru (TMT mulai paling akhir) di atas. Isi `employee_id` untuk riwayat jabatan satu karyawan.
     */
    public function index(Request $request): JsonResponse
    {
        $assignments = PositionAssignment::query()
            ->with(AssignmentResource::RELATIONS)
            ->when($request->query('employee_id'), fn ($query, $employeeId) => $query->where('employee_id', $employeeId))
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(AssignmentResource::collection($assignments), 'Daftar penugasan jabatan.');
    }

    /**
     * Detail penugasan jabatan
     */
    public function show(PositionAssignment $positionAssignment): JsonResponse
    {
        return ApiResponse::success(AssignmentResource::make($positionAssignment->load(AssignmentResource::RELATIONS)), 'Detail penugasan jabatan.');
    }

    /**
     * File dokumen penugasan
     *
     * Mengirim isi file (PDF / gambar), bukan JSON. 404 kalau penugasan belum punya file.
     */
    public function decreeFile(PositionAssignment $positionAssignment, FileStorage $fileStorage): StreamedResponse
    {
        abort_if($positionAssignment->decreeFile === null, 404, 'File tidak ditemukan.');

        return $fileStorage->respond($positionAssignment->decreeFile);
    }
}
