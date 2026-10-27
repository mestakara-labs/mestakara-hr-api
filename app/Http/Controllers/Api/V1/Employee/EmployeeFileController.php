<?php

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\FileStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * File milik karyawan, untuk ditampilkan di browser.
 *
 *   GET /employees/{id}/files?type=foto                       foto karyawan
 *   GET /employees/{id}/files?type=ijazah&education={id}      ijazah satu riwayat pendidikan
 */
class EmployeeFileController extends Controller
{
    /**
     * File karyawan
     *
     * Mengirim isi file (PDF / gambar), bukan JSON. 404 kalau filenya tidak ada.
     */
    public function __invoke(Request $request, Employee $employee, FileStorage $fileStorage): StreamedResponse
    {
        $file = match ($request->query('type')) {
            'foto' => $employee->photo,
            'ijazah' => $employee->educations()->find($request->query('education'))?->certificateFile,
            default => null,
        };

        abort_if($file === null, 404, 'File tidak ditemukan.');

        return $fileStorage->respond($file);
    }
}
