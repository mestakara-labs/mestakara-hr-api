<?php

namespace App\Services\Transfer;

use App\Models\PositionAssignment;
use Illuminate\Support\Collection;

/**
 * HASIL rencana pengalihan atasan otomatis sebuah usulan mutasi (dibuat oleh SupervisorPlanner).
 *
 * Di dalam rencana, atasan bisa berupa:
 *   - penugasan yang SUDAH ADA  -> id penugasan biasa
 *   - penugasan BARU dari usulan ini (belum dibuat) -> rujukan "baru:<id item>" (lihat newRef)
 *
 * Isi $changes, satu baris per bawahan yang atasannya berubah:
 *   [
 *     'assignment_id'       => penugasan bawahan,
 *     'to_refs'             => atasan barunya (rujukan),
 *     'reason'              => penjelasan singkat,
 *     'vacated_position_id' => jabatan atasan yang kosong (kalau dialihkan ke atas), atau null,
 *   ]
 */
class SupervisorPlan
{
    private const NEW_PREFIX = 'baru:';

    /**
     * @param  list<array{assignment_id: string, to_refs: list<string>, reason: string, vacated_position_id: ?string}>  $changes
     * @param  array<string, int>  $restorableCounts  jumlah bawahan yang BISA dikembalikan, per jabatan tujuan
     * @param  list<string>  $endedIds  penugasan yang diakhiri usulan ini
     * @param  Collection<string, PositionAssignment>  $assignments  penugasan yang belum selesai (key = id), dengan relasi supervisors
     * @param  list<array>  $items  item usulan (id, from_assignment_id, position_id, restore_subordinates)
     */
    public function __construct(
        public readonly array $changes,
        public readonly array $restorableCounts,
        private readonly array $endedIds,
        private readonly Collection $assignments,
        private readonly array $items,
    ) {}

    /** Rujukan ke penugasan baru dari item usulan, mis. "baru:0199-...". */
    public static function newRef(string $itemId): string
    {
        return self::NEW_PREFIX.$itemId;
    }

    /** "baru:<id item>" -> id item. null kalau rujukannya penugasan yang sudah ada. */
    public static function itemIdOfRef(string $ref): ?string
    {
        return str_starts_with($ref, self::NEW_PREFIX) ? substr($ref, strlen(self::NEW_PREFIX)) : null;
    }

    /**
     * Ganti atasan yang IKUT DIAKHIRI usulan ini dengan penggantinya. Dipakai untuk atasan penugasan baru:
     * kalau atasan yang dipilih ternyata ikut dimutasi di usulan yang sama, diganti pemegang barunya / atasannya.
     *
     * @param  list<string>  $supervisorIds
     * @return list<string> rujukan atasan
     */
    public function resolve(array $supervisorIds): array
    {
        $resolved = [];

        foreach ($supervisorIds as $id) {
            $replacements = in_array($id, $this->endedIds, true) ? $this->replacementOf($id, []) : [$id];
            array_push($resolved, ...$replacements);
        }

        return array_values(array_unique($resolved));
    }

    /** Pemegang BARU sebuah jabatan dari usulan ini, sebagai rujukan "baru:...". */
    public function newHoldersOf(?string $positionId): array
    {
        $refs = [];

        foreach ($this->items as $item) {
            if ($item['position_id'] === $positionId) {
                $refs[] = self::newRef($item['id']);
            }
        }

        return $refs;
    }

    /**
     * Pengganti atasan yang diakhiri:
     *   a. pemegang BARU jabatannya di usulan ini, kalau ada;
     *   b. kalau tidak, ATASAN dari atasan itu (naik satu tingkat; berulang kalau ikut diakhiri juga).
     *
     * @param  list<string>  $visited  penjaga supaya tidak berputar-putar
     */
    private function replacementOf(string $endedId, array $visited): array
    {
        if (in_array($endedId, $visited, true)) {
            return [];
        }
        $visited[] = $endedId;

        $ended = $this->assignments->get($endedId);
        if ($ended === null) {
            return [];
        }

        $newHolders = $this->newHoldersOf($ended->position_id);
        if ($newHolders !== []) {
            return $newHolders;
        }

        $replacements = [];
        foreach ($ended->supervisors->pluck('id') as $supervisorId) {
            $next = in_array($supervisorId, $this->endedIds, true) ? $this->replacementOf($supervisorId, $visited) : [$supervisorId];
            array_push($replacements, ...$next);
        }

        return $replacements;
    }
}
