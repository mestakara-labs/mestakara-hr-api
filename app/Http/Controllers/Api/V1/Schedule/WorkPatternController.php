<?php

namespace App\Http\Controllers\Api\V1\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\WorkPatternRequest;
use App\Http\Resources\Schedule\WorkPatternResource;
use App\Models\PatternSlot;
use App\Models\WorkPattern;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Master Pola Kerja (slot × hari siklus disimpan sekaligus).
 *
 *   GET    /work-patterns          daftar
 *   POST   /work-patterns          tambah
 *   GET    /work-patterns/{id}     detail
 *   PUT    /work-patterns/{id}     ubah
 *   DELETE /work-patterns/{id}     hapus (ditolak kalau masih dipakai regu kerja)
 */
class WorkPatternController extends Controller
{
    /**
     * Daftar pola kerja
     */
    public function index(): JsonResponse
    {
        $patterns = WorkPattern::query()->with('slots.days')->withCount('workGroups')->orderBy('name')->get();

        return ApiResponse::success(WorkPatternResource::collection($patterns), 'Daftar pola kerja.');
    }

    /**
     * Tambah pola kerja
     */
    public function store(WorkPatternRequest $request): JsonResponse
    {
        $pattern = $this->save(new WorkPattern, $request);

        return ApiResponse::success($this->resource($pattern), 'Pola kerja berhasil ditambahkan.', 201);
    }

    /**
     * Detail pola kerja
     */
    public function show(WorkPattern $workPattern): JsonResponse
    {
        return ApiResponse::success($this->resource($workPattern), 'Detail pola kerja.');
    }

    /**
     * Ubah pola kerja
     *
     * Slot yang masih menjadi slot awal anggota regu tidak bisa dihapus.
     */
    public function update(WorkPatternRequest $request, WorkPattern $workPattern): JsonResponse
    {
        $pattern = $this->save($workPattern, $request);

        return ApiResponse::success($this->resource($pattern), 'Pola kerja berhasil diperbarui.');
    }

    /**
     * Hapus pola kerja
     *
     * Ditolak (409) kalau masih dipakai regu kerja.
     */
    public function destroy(WorkPattern $workPattern): JsonResponse
    {
        $groupCount = $workPattern->workGroups()->count();

        if ($groupCount > 0) {
            abort(409, "Pola \"{$workPattern->name}\" masih dipakai {$groupCount} regu kerja. Ganti pola regu tersebut terlebih dahulu.");
        }

        $workPattern->delete();

        return ApiResponse::success(null, 'Pola kerja berhasil dihapus.');
    }

    /**
     * Simpan pola + slot + isi hari dalam satu transaksi. Slot lama (id sama) dipertahankan supaya
     * anggota regu yang merujuknya tetap benar; slot baru ("baru-...") dibuat; slot yang tidak dikirim dihapus.
     */
    private function save(WorkPattern $pattern, WorkPatternRequest $request): WorkPattern
    {
        return DB::transaction(function () use ($pattern, $request) {
            $pattern->fill($request->safe()->only(['name', 'cycle_days', 'description']))->save();

            $existing = $pattern->slots()->get()->keyBy('id');
            $keptIds = [];

            foreach ($request->slots() as $order => $row) {
                $slot = $existing->get($row['id']) ?? new PatternSlot(['work_pattern_id' => $pattern->id]);
                $slot->fill([
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'sort_order' => $order + 1,
                    'rotates' => $row['rotates'],
                ])->save();

                // Isi hari ditulis ulang seluruhnya (tidak ada tabel lain yang merujuk ke baris ini).
                $slot->days()->delete();
                foreach ($row['shift_ids'] as $index => $shiftId) {
                    $slot->days()->create(['day_index' => $index + 1, 'shift_id' => $shiftId]);
                }

                $keptIds[] = $slot->id;
            }

            $pattern->slots()->whereKeyNot($keptIds)->delete();

            return $pattern;
        });
    }

    private function resource(WorkPattern $pattern): WorkPatternResource
    {
        return WorkPatternResource::make($pattern->load('slots.days')->loadCount('workGroups'));
    }
}
