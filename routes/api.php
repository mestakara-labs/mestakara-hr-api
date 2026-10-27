<?php

/*
|--------------------------------------------------------------------------
| DAFTAR ROUTE API — HR Dashboard Mestakara
|--------------------------------------------------------------------------
|
| Semua alamat di file ini otomatis diawali /api/v1 (lihat bootstrap/app.php).
| Route dikelompokkan per modul, urutannya sama dengan menu di front end.
|
| Cara menambah endpoint baru:
|   1. Buat controller di app/Http/Controllers/Api/V1/<Modul>/
|   2. Daftarkan route-nya di kelompok modul yang sesuai di bawah ini.
|   3. Cek dengan: php artisan route:list --path=api
|
*/

use App\Http\Controllers\Api\V1\Activation\EmployeeActivationController;
use App\Http\Controllers\Api\V1\Assignment\PositionAssignmentController;
use App\Http\Controllers\Api\V1\Assignment\SupervisorController;
use App\Http\Controllers\Api\V1\Attendance\AttendanceImportController;
use App\Http\Controllers\Api\V1\Attendance\AttendanceSummaryController;
use App\Http\Controllers\Api\V1\Attendance\EmployeeAttendanceController;
use App\Http\Controllers\Api\V1\Attendance\OvertimeDwController;
use App\Http\Controllers\Api\V1\Attendance\OvertimeEntryController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Employee\EmployeeController;
use App\Http\Controllers\Api\V1\Employee\EmployeeExportController;
use App\Http\Controllers\Api\V1\Employee\EmployeeFileController;
use App\Http\Controllers\Api\V1\Employee\EmployeeStatusHistoryController;
use App\Http\Controllers\Api\V1\Organization\DivisionController;
use App\Http\Controllers\Api\V1\Organization\OrganizationController;
use App\Http\Controllers\Api\V1\Organization\PlacementCategoryController;
use App\Http\Controllers\Api\V1\Organization\PlacementObjectController;
use App\Http\Controllers\Api\V1\Organization\PositionController;
use App\Http\Controllers\Api\V1\Organization\SubUnitController;
use App\Http\Controllers\Api\V1\Payroll\CostCenterController;
use App\Http\Controllers\Api\V1\Payroll\EmployeeBankAccountController;
use App\Http\Controllers\Api\V1\Payroll\EmployeeCostCenterController;
use App\Http\Controllers\Api\V1\Payroll\EmployeeWageSchemeController;
use App\Http\Controllers\Api\V1\Payroll\OvertimeSettingController;
use App\Http\Controllers\Api\V1\Payroll\PayrollController;
use App\Http\Controllers\Api\V1\Payroll\PayrollDeductionController;
use App\Http\Controllers\Api\V1\Payroll\PayrollExportController;
use App\Http\Controllers\Api\V1\Payroll\PayrollGroupController;
use App\Http\Controllers\Api\V1\Payroll\SignatureSettingController;
use App\Http\Controllers\Api\V1\Payroll\WageSchemeController;
use App\Http\Controllers\Api\V1\Payroll\WageSchemeImportController;
use App\Http\Controllers\Api\V1\Payroll\WageSchemeTemplateController;
use App\Http\Controllers\Api\V1\Schedule\HolidayController;
use App\Http\Controllers\Api\V1\Schedule\ScheduleController;
use App\Http\Controllers\Api\V1\Schedule\SchedulePeriodController;
use App\Http\Controllers\Api\V1\Schedule\ShiftController;
use App\Http\Controllers\Api\V1\Schedule\WorkGroupController;
use App\Http\Controllers\Api\V1\Schedule\WorkPatternController;
use App\Http\Controllers\Api\V1\Termination\EmployeeTerminationController;
use App\Http\Controllers\Api\V1\Transfer\TransferController;
use App\Http\Controllers\Api\V1\Transfer\TransferDocumentController;
use App\Http\Controllers\Api\V1\Transfer\TransferStatusController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Login (tanpa token)
// ---------------------------------------------------------------------------
Route::post('/login', [AuthController::class, 'login']);

// ---------------------------------------------------------------------------
// Semua route di bawah ini WAJIB login (header Authorization: Bearer <token>)
// ---------------------------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {

    // --- Akun login -------------------------------------------------------
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // --- Organisasi & Jabatan ----------------------------------------------
    // apiResource = 5 route: GET daftar, POST tambah, GET detail, PUT ubah, DELETE hapus.
    Route::get('/organization', OrganizationController::class);
    Route::apiResource('divisions', DivisionController::class);
    Route::apiResource('sub-units', SubUnitController::class);
    Route::apiResource('placement-categories', PlacementCategoryController::class);
    Route::apiResource('placement-objects', PlacementObjectController::class);
    Route::apiResource('positions', PositionController::class);

    // --- Kepegawaian ---------------------------------------------------------
    // /export didaftarkan SEBELUM apiResource supaya "export" tidak dianggap id karyawan.
    Route::get('/employees/export', EmployeeExportController::class);
    Route::apiResource('employees', EmployeeController::class);
    Route::get('/employees/{employee}/files', EmployeeFileController::class);
    Route::get('/employees/{employee}/status-history', EmployeeStatusHistoryController::class);

    // --- Penambahan Karyawan -------------------------------------------------------
    Route::apiResource('employee-activations', EmployeeActivationController::class);
    Route::post('/employee-activations/{employee_activation}/documents', [EmployeeActivationController::class, 'saveDocument']);
    Route::post('/employee-activations/{employee_activation}/cancel', [EmployeeActivationController::class, 'cancel']);
    Route::get('/employee-activations/{employee_activation}/files', [EmployeeActivationController::class, 'file']);

    // --- Penonaktifan Karyawan -----------------------------------------------------
    // /preview didaftarkan SEBELUM apiResource supaya "preview" tidak dianggap id usulan.
    Route::post('/employee-terminations/preview', [EmployeeTerminationController::class, 'preview']);
    Route::apiResource('employee-terminations', EmployeeTerminationController::class);
    Route::post('/employee-terminations/{employee_termination}/documents', [EmployeeTerminationController::class, 'saveDocument']);
    Route::post('/employee-terminations/{employee_termination}/cancel', [EmployeeTerminationController::class, 'cancel']);
    Route::get('/employee-terminations/{employee_termination}/files', [EmployeeTerminationController::class, 'file']);

    // --- Shift & Jadwal ------------------------------------------------------------
    Route::apiResource('shifts', ShiftController::class);
    Route::apiResource('holidays', HolidayController::class);
    Route::apiResource('work-patterns', WorkPatternController::class);
    Route::apiResource('work-groups', WorkGroupController::class);
    Route::get('/work-groups/{work_group}/members', [WorkGroupController::class, 'members']);
    Route::put('/work-groups/{work_group}/members/{member}', [WorkGroupController::class, 'endMember'])->scopeBindings();
    Route::get('/work-groups/{work_group}/projection', [WorkGroupController::class, 'projection']);

    Route::apiResource('schedule-periods', SchedulePeriodController::class)->except('update');
    Route::post('/schedule-periods/{schedule_period}/publish', [SchedulePeriodController::class, 'publish']);
    Route::post('/schedule-periods/{schedule_period}/lock', [SchedulePeriodController::class, 'lock']);
    Route::post('/schedule-periods/{schedule_period}/regenerate/preview', [SchedulePeriodController::class, 'regeneratePreview']);
    Route::post('/schedule-periods/{schedule_period}/regenerate', [SchedulePeriodController::class, 'regenerate']);
    Route::put('/schedules/{schedule}', [ScheduleController::class, 'update']);
    Route::get('/schedules/{schedule}/changes', [ScheduleController::class, 'changes']);

    // --- Jabatan Karyawan (penugasan; hanya dibaca + ubah atasan) -------------
    Route::get('/position-assignments', [PositionAssignmentController::class, 'index']);
    Route::post('/position-assignments/supervisors', [SupervisorController::class, 'update']);
    Route::get('/position-assignments/{positionAssignment}', [PositionAssignmentController::class, 'show']);
    Route::get('/position-assignments/{positionAssignment}/decree-file', [PositionAssignmentController::class, 'decreeFile']);
    Route::get('/position-assignments/{positionAssignment}/supervisor-history', [SupervisorController::class, 'history']);

    // --- Mutasi & Penempatan -----------------------------------------------------
    // /transfers/preview didaftarkan SEBELUM apiResource supaya "preview" tidak dianggap id usulan.
    Route::post('/transfers/preview', [TransferController::class, 'preview']);
    Route::apiResource('transfers', TransferController::class);
    Route::post('/transfers/{transfer}/activate', [TransferStatusController::class, 'activate']);
    Route::post('/transfers/{transfer}/cancel', [TransferStatusController::class, 'cancel']);
    Route::post('/transfers/{transfer}/basis-document', [TransferDocumentController::class, 'saveBasisDocument']);
    Route::post('/transfers/{transfer}/items/{item}/excerpt', [TransferDocumentController::class, 'uploadExcerpt'])->scopeBindings();
    Route::get('/transfers/{transfer}/documents', [TransferDocumentController::class, 'show']);

    // --- Data Dasar Upah (Casual & Daily Worker) -----------------------------------
    // Riwayat (rekening, cost center, skema upah) hanya bisa DITAMBAH, tidak diubah/dihapus.
    Route::apiResource('cost-centers', CostCenterController::class);
    Route::apiResource('payroll-groups', PayrollGroupController::class);
    Route::get('/employees/{employee}/bank-accounts', [EmployeeBankAccountController::class, 'index']);
    Route::post('/employees/{employee}/bank-accounts', [EmployeeBankAccountController::class, 'store']);
    Route::get('/employees/{employee}/cost-centers', [EmployeeCostCenterController::class, 'index']);
    Route::post('/employees/{employee}/cost-centers', [EmployeeCostCenterController::class, 'store']);
    Route::get('/employees/{employee}/wage-schemes', [EmployeeWageSchemeController::class, 'index']);
    Route::post('/employees/{employee}/wage-schemes', [EmployeeWageSchemeController::class, 'store']);
    Route::get('/wage-schemes', [WageSchemeController::class, 'index']);
    Route::post('/wage-schemes/bulk', [WageSchemeController::class, 'bulk']);
    Route::get('/wage-schemes/template', WageSchemeTemplateController::class);
    Route::post('/wage-schemes/import/preview', [WageSchemeImportController::class, 'preview']);
    Route::post('/wage-schemes/import', [WageSchemeImportController::class, 'store']);
    Route::get('/overtime-settings', [OvertimeSettingController::class, 'show']);
    Route::put('/overtime-settings', [OvertimeSettingController::class, 'update']);

    // --- Unggah Absensi (Casual & Daily Worker) ----------------------------------------
    // /template & /preview didaftarkan SEBELUM POST /attendance-imports supaya alamatnya jelas.
    Route::get('/attendance-imports', [AttendanceImportController::class, 'index']);
    Route::get('/attendance-imports/template', [AttendanceImportController::class, 'template']);
    Route::post('/attendance-imports/preview', [AttendanceImportController::class, 'preview']);
    Route::post('/attendance-imports', [AttendanceImportController::class, 'store']);
    Route::get('/attendance-summaries', AttendanceSummaryController::class);
    Route::get('/overtime-entries', [OvertimeEntryController::class, 'index']);
    Route::post('/overtime-entries', [OvertimeEntryController::class, 'store']);
    Route::put('/overtime-entries/{overtimeEntry}', [OvertimeEntryController::class, 'update']);
    Route::delete('/overtime-entries/{overtimeEntry}', [OvertimeEntryController::class, 'destroy']);

    // --- Lembur DW otomatis (Tahap 3) ----------------------------------------------------
    Route::get('/overtime-dw/targets', [OvertimeDwController::class, 'targets']);
    Route::post('/overtime-dw/recommendations/preview', [OvertimeDwController::class, 'preview']);
    Route::post('/overtime-dw/recommendations', [OvertimeDwController::class, 'store']);
    Route::get('/employees/{employee}/attendance', [EmployeeAttendanceController::class, 'show']);
    Route::put('/employees/{employee}/attendance', [EmployeeAttendanceController::class, 'update']);

    // --- Penanda tangan Daftar Upah & SPKL (Tahap 4) -------------------------------------
    Route::get('/signature-settings/general', [SignatureSettingController::class, 'general']);
    Route::put('/signature-settings/general', [SignatureSettingController::class, 'updateGeneral']);
    Route::get('/signature-settings/payroll-groups', [SignatureSettingController::class, 'payrollGroups']);
    Route::get('/signature-settings/payroll-groups/{payroll_group}', [SignatureSettingController::class, 'payrollGroup']);
    Route::put('/signature-settings/payroll-groups/{payroll_group}', [SignatureSettingController::class, 'updatePayrollGroup']);
    Route::get('/signature-settings/divisions', [SignatureSettingController::class, 'divisions']);
    Route::get('/signature-settings/divisions/{division}', [SignatureSettingController::class, 'division']);
    Route::put('/signature-settings/divisions/{division}', [SignatureSettingController::class, 'updateDivision']);

    // --- Daftar Upah, potongan, kunci periode & ekspor (Tahap 4) ------------------------------
    Route::get('/payroll', [PayrollController::class, 'index']);
    Route::post('/payroll/lock', [PayrollController::class, 'lock']);
    Route::post('/payroll/unlock', [PayrollController::class, 'unlock']);
    Route::put('/payroll/deductions', [PayrollDeductionController::class, 'update']);
    Route::post('/payroll/deductions/copy-previous', [PayrollDeductionController::class, 'copyPrevious']);
    Route::get('/payroll/export', [PayrollExportController::class, 'payroll']);
    Route::get('/payroll/spkl', [PayrollExportController::class, 'spkl']);

});
