<?php

namespace App\Http\Controllers\Api\V1\Employee;

use App\Enums\ProposalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\EmployeeIndexRequest;
use App\Http\Requests\Employee\EmployeeRequest;
use App\Http\Resources\Employee\EmployeeProfileResource;
use App\Models\Employee;
use App\Models\EmployeeActivation;
use App\Services\Activation\ActivationService;
use App\Services\EmployeeService;
use App\Support\ApiResponse;
use App\Support\DataPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Data Karyawan.
 *
 *   GET    /employees          daftar semua karyawan (disaring di front end); ?period=YYYY-MM = per periode
 *   POST   /employees          tambah   (multipart/form-data, lihat EmployeeRequest)
 *   GET    /employees/{id}     detail
 *   PUT    /employees/{id}     ubah     (multipart/form-data)
 *   DELETE /employees/{id}     hapus (hanya draf karyawan Belum Aktif)
 *
 * Karyawan baru selalu berstatus Belum Aktif; menjadi Aktif lewat usulan Penambahan Karyawan.
 */
class EmployeeController extends Controller
{
    /** Relasi yang dibutuhkan EmployeeProfileResource. */
    private const RELATIONS = EmployeeProfileResource::RELATIONS;

    public function __construct(private EmployeeService $employeeService) {}

    /**
     * Daftar karyawan
     *
     * `period=YYYY-MM` (opsional): status kepegawaian, masa kerja, dan jabatan utama dihitung per periode itu
     * (lihat App\Support\DataPeriod). Tanpa `period` = per hari ini.
     */
    public function index(EmployeeIndexRequest $request): JsonResponse
    {
        $asOf = DataPeriod::asOf($request->validated('period'));
        $relations = $asOf === null ? self::RELATIONS : EmployeeProfileResource::RELATIONS_FOR_PERIOD;

        $employees = Employee::query()->with($relations)->orderBy('id')->get();

        return ApiResponse::success(
            $employees->map(fn (Employee $employee) => EmployeeProfileResource::make($employee)->asOf($asOf)),
            'Daftar karyawan.',
        );
    }

    /**
     * Tambah karyawan
     *
     * Dikirim sebagai multipart/form-data. `educations` & `family_members` berupa teks JSON.
     * `save_mode=draft` = cukup Nama + Status Karyawan.
     * `activation_id` (opsional) = karyawan baru ini langsung dimasukkan ke usulan Penambahan (Draf) itu.
     */
    public function store(EmployeeRequest $request, ActivationService $activationService): JsonResponse
    {
        $activation = null;
        if ($request->filled('activation_id')) {
            $activation = EmployeeActivation::find($request->input('activation_id'));
            abort_if(
                $activation?->status !== ProposalStatus::Draft,
                409,
                'Usulan Penambahan tidak ditemukan atau sudah tidak bisa diubah.',
            );
        }

        $employee = DB::transaction(function () use ($request, $activation, $activationService) {
            $employee = $this->employeeService->save(new Employee, $request);
            if ($activation !== null) {
                $activationService->addEmployee($activation, $employee);
            }

            return $employee;
        });

        $message = $request->isDraft() ? 'Draf karyawan berhasil disimpan.' : 'Karyawan berhasil ditambahkan.';

        return ApiResponse::success(EmployeeProfileResource::make($employee->load(self::RELATIONS)), $message, 201);
    }

    /**
     * Detail karyawan
     */
    public function show(Employee $employee): JsonResponse
    {
        return ApiResponse::success(EmployeeProfileResource::make($employee->load(self::RELATIONS)), 'Detail karyawan.');
    }

    /**
     * Ubah karyawan
     *
     * Dikirim sebagai multipart/form-data, sama dengan tambah. Foto lama dipertahankan dengan `keep_photo=1`,
     * ijazah lama dengan `keep_certificate_file: true` di baris pendidikannya.
     */
    public function update(EmployeeRequest $request, Employee $employee): JsonResponse
    {
        $employee = $this->employeeService->save($employee, $request);
        $message = $request->isDraft() ? 'Draf karyawan berhasil disimpan.' : 'Data karyawan berhasil diperbarui.';

        return ApiResponse::success(EmployeeProfileResource::make($employee->load(self::RELATIONS)), $message);
    }

    /**
     * Hapus karyawan
     *
     * Hanya DRAF karyawan yang boleh dihapus: Belum Aktif, tidak punya riwayat jabatan, dan belum pernah
     * masuk usulan Penambahan selain Draf (termasuk yang Dibatalkan). Karyawan lain dinonaktifkan lewat
     * Penonaktifan Karyawan, supaya riwayatnya tetap ada. Draf yang dihapus ikut keluar dari usulan Draf-nya.
     */
    public function destroy(Employee $employee): JsonResponse
    {
        $wasProposed = $employee->activationItems()
            ->whereHas('activation', fn ($query) => $query->where('status', '!=', ProposalStatus::Draft->value))
            ->exists();

        if (! $employee->isPending() || $wasProposed || $employee->assignments()->exists()) {
            abort(409, "\"{$employee->name}\" pernah aktif atau punya riwayat jabatan, jadi tidak bisa dihapus. Gunakan Penonaktifan Karyawan.");
        }

        $this->employeeService->delete($employee);

        return ApiResponse::success(null, 'Draf karyawan berhasil dihapus.');
    }
}
