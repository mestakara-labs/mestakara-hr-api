<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Enums\ImportRowStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\WageSchemeImportRequest;
use App\Services\Payroll\WageSchemeImportChecker;
use App\Services\Payroll\WageSchemeImporter;
use App\Services\Payroll\WageSchemeImportReader;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Impor Skema Upah dari template Excel (multipart/form-data, isian `file`).
 *
 *   POST /wage-schemes/import/preview   periksa saja, TIDAK menyimpan: status & pesan per baris
 *   POST /wage-schemes/import           simpan, HANYA kalau tidak ada baris salah (kalau ada -> 422)
 *
 * Alurnya: WageSchemeImportReader membaca baris -> WageSchemeImportChecker memeriksa ->
 * WageSchemeImporter menyimpan riwayat yang berubah.
 */
class WageSchemeImportController extends Controller
{
    public function __construct(
        private WageSchemeImportReader $reader,
        private WageSchemeImportChecker $checker,
    ) {}

    /**
     * Pratinjau impor skema upah
     *
     * `rows[].status`: berubah / tidak berubah / salah. `rows[].errors`: [{ column, message }].
     * `rows[].changes`: bagian yang akan dicatat { wage_scheme, bank_account, cost_center }.
     */
    public function preview(WageSchemeImportRequest $request): JsonResponse
    {
        $results = $this->checker->check($this->reader->rows($request->file('file')));

        return ApiResponse::success([
            'summary' => WageSchemeImportChecker::summary($results),
            'rows' => array_map(fn (array $result) => $this->rowForResponse($result), $results),
        ], 'Pratinjau impor skema upah.');
    }

    /**
     * Simpan impor skema upah
     *
     * Ditolak 422 kalau masih ada baris salah (pesan per baris di `errors["rows.<nomor baris>"]`).
     * Respons: jumlah karyawan yang berubah & jumlah catatan baru per jenis.
     */
    public function store(WageSchemeImportRequest $request, WageSchemeImporter $importer): JsonResponse
    {
        $results = $this->checker->check($this->reader->rows($request->file('file')));

        $invalidRows = array_filter($results, fn (array $result) => $result['status'] === ImportRowStatus::Invalid->value);
        if ($invalidRows !== []) {
            $errors = [];
            foreach ($invalidRows as $result) {
                $errors["rows.{$result['row']}"] = array_map(
                    fn (string $column, string $message) => "Baris {$result['row']} – {$column}: {$message}",
                    array_keys($result['errors']),
                    $result['errors'],
                );
            }

            return ApiResponse::error('Masih ada '.count($invalidRows).' baris yang salah. Perbaiki file lalu unggah kembali.', 422, $errors);
        }

        $saved = $importer->save($results);

        return ApiResponse::success($saved, "Impor skema upah berhasil: {$saved['employee_count']} karyawan diperbarui.");
    }

    /** Satu baris hasil pemeriksaan untuk front end (tanpa data internal `records`). */
    private function rowForResponse(array $result): array
    {
        return [
            'row' => $result['row'],
            'employee_id' => $result['employee_id'],
            'registration_number' => $result['registration_number'],
            'name' => $result['name'],
            'employment_status' => $result['employment_status'],
            'status' => $result['status'],
            'errors' => array_map(
                fn (string $column, string $message) => ['column' => $column, 'message' => $message],
                array_keys($result['errors']),
                $result['errors'],
            ),
            'changes' => $result['changes'],
        ];
    }
}
