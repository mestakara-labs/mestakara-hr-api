<?php

namespace App\Http\Controllers\Api\V1\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Resources\Schedule\SchedulePeriodResource;
use App\Models\SchedulePeriod;
use App\Models\WorkGroup;
use App\Services\Schedule\ScheduleGenerator;
use App\Services\Schedule\SchedulePeriodService;
use App\Services\Schedule\ScheduleRegenerator;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Jadwal Kerja resmi per periode (satu regu × satu bulan).
 *
 *   GET    /schedule-periods?group_id=&month=            daftar (filter boleh kosong)
 *   POST   /schedule-periods                             Buat Jadwal (Draf) { group_id, month }
 *   GET    /schedule-periods/{id}                        detail (+ baris jadwal & shift kosong)
 *   DELETE /schedule-periods/{id}                        hapus Draf
 *   POST   /schedule-periods/{id}/publish                Draf -> Terbit
 *   POST   /schedule-periods/{id}/lock                   Terbit -> Terkunci
 *   POST   /schedule-periods/{id}/regenerate/preview     pratinjau Buat Ulang dari Tanggal
 *   POST   /schedule-periods/{id}/regenerate             terapkan Buat Ulang dari Tanggal
 */
class SchedulePeriodController extends Controller
{
    public function __construct(private SchedulePeriodService $periodService) {}

    /**
     * Daftar periode jadwal
     */
    public function index(Request $request): JsonResponse
    {
        $periods = SchedulePeriod::query()
            ->with(SchedulePeriodResource::RELATIONS)
            ->when($request->query('group_id'), fn ($query, $groupId) => $query->where('work_group_id', $groupId))
            ->when($request->query('month'), fn ($query, $month) => $query->where('month', $month))
            ->orderByDesc('month')
            ->get();

        return ApiResponse::success(SchedulePeriodResource::collection($periods), 'Daftar periode jadwal.');
    }

    /**
     * Buat jadwal
     *
     * Body JSON: `{ group_id, month: "YYYY-MM" }`. Hanya bulan berjalan & bulan depan.
     */
    public function store(Request $request): JsonResponse
    {
        $group = WorkGroup::find($request->input('group_id'));
        if ($group === null) {
            throw ValidationException::withMessages(['group_id' => 'Pilih Regu Kerja']);
        }

        $month = (string) $request->input('month');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw ValidationException::withMessages(['month' => 'Pilih Bulan']);
        }

        $period = $this->periodService->create($group, $month, auth()->id());

        return ApiResponse::success(
            $this->detail($period),
            "Jadwal {$this->periodService->formatMonth($month)} berhasil dibuat (Draf).",
            201,
        );
    }

    /**
     * Detail periode jadwal
     */
    public function show(SchedulePeriod $schedulePeriod): JsonResponse
    {
        return ApiResponse::success($this->detail($schedulePeriod), 'Detail periode jadwal.');
    }

    /**
     * Hapus jadwal Draf
     */
    public function destroy(SchedulePeriod $schedulePeriod): JsonResponse
    {
        $this->periodService->delete($schedulePeriod);

        return ApiResponse::success(null, 'Draf jadwal berhasil dihapus.');
    }

    /**
     * Terbitkan jadwal
     */
    public function publish(SchedulePeriod $schedulePeriod): JsonResponse
    {
        return ApiResponse::success($this->detail($this->periodService->publish($schedulePeriod)), 'Jadwal berhasil diterbitkan.');
    }

    /**
     * Kunci jadwal
     */
    public function lock(SchedulePeriod $schedulePeriod): JsonResponse
    {
        return ApiResponse::success($this->detail($this->periodService->lock($schedulePeriod)), 'Jadwal berhasil dikunci.');
    }

    /**
     * Pratinjau buat ulang
     *
     * Body JSON: `{ from_date, keep_manual, reason }`. Tidak menyimpan apa pun.
     */
    public function regeneratePreview(Request $request, SchedulePeriod $schedulePeriod, ScheduleRegenerator $regenerator): JsonResponse
    {
        [$fromDate, $keepManual] = $this->checkRegenerate($request, $schedulePeriod, isApply: false);

        return ApiResponse::success($regenerator->preview($schedulePeriod, $fromDate, $keepManual), 'Pratinjau buat ulang jadwal.');
    }

    /**
     * Buat ulang dari tanggal
     *
     * Body JSON: `{ from_date, keep_manual, reason }`. Alasan wajib kalau jadwal sudah Terbit.
     */
    public function regenerate(Request $request, SchedulePeriod $schedulePeriod, ScheduleRegenerator $regenerator): JsonResponse
    {
        [$fromDate, $keepManual] = $this->checkRegenerate($request, $schedulePeriod, isApply: true);

        DB::transaction(fn () => $regenerator->apply($schedulePeriod, $fromDate, $keepManual, trim((string) $request->input('reason'))));

        return ApiResponse::success(null, 'Jadwal berhasil dibuat ulang dari pola.');
    }

    /** Periksa permintaan buat ulang. Hasilnya [from_date, keep_manual]. */
    private function checkRegenerate(Request $request, SchedulePeriod $period, bool $isApply): array
    {
        abort_unless($period->status->isEditable(), 409, 'Jadwal yang sudah dikunci tidak bisa dibuat ulang.');

        $fromDate = (string) $request->input('from_date');
        $errors = [];

        if (! in_array($fromDate, app(ScheduleGenerator::class)->datesOfMonth($period->month), true)) {
            $errors['from_date'] = 'Pilih tanggal di dalam bulan jadwal ini';
        }
        if ($isApply && $period->status->requiresChangeReason() && trim((string) $request->input('reason')) === '') {
            $errors['reason'] = 'Alasan wajib diisi karena jadwal sudah diterbitkan';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$fromDate, $request->boolean('keep_manual')];
    }

    private function detail(SchedulePeriod $period): SchedulePeriodResource
    {
        return SchedulePeriodResource::make($period->load(SchedulePeriodResource::RELATIONS))->withDetail();
    }
}
