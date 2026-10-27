<?php

namespace App\Services\Termination;

use App\Enums\ProposalStatus;
use App\Enums\TerminationType;
use App\Http\Requests\Termination\TerminationRequest;
use App\Models\EmployeeActivationItem;
use App\Models\EmployeeTermination;
use App\Models\EmployeeTerminationItem;
use App\Models\PositionAssignment;
use App\Models\WorkGroupMember;
use App\Services\EmployeeStatusService;
use App\Services\FileStorage;
use App\Services\StatusDocumentService;
use App\Services\SupervisorService;
use App\Support\StatusDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ALUR usulan Penonaktifan Karyawan (semua perubahan data ada di sini, di dalam transaksi):
 *
 *   save()          simpan usulan baru / ubah usulan Draf; action "submit" = langsung Berlaku
 *   delete()        hapus usulan Draf
 *   saveDocument()  ganti dokumen usulan Berlaku (kolektif atau per orang)
 *   cancel()        batalkan usulan Berlaku (alasan wajib): semua dampaknya dikembalikan
 *
 * BERLAKU (dampak dihitung TerminationImpact), untuk setiap karyawan:
 *   1. bawahannya dialihkan ke atasan karyawan itu (Riwayat Atasan: "Penonaktifan <nomor>")
 *   2. jabatannya diakhiri sehari sebelum TMT nonaktif (nilai lama disimpan untuk pembatalan)
 *   3. status kepegawaian diperbarui (Nonaktif kalau TMT sudah tiba) + akun login dinonaktifkan
 */
class TerminationService
{
    public function __construct(
        private StatusDocumentService $documents,
        private FileStorage $fileStorage,
        private EmployeeStatusService $statusService,
        private SupervisorService $supervisorService,
        private TerminationImpact $impact,
    ) {}

    /** Simpan usulan (baru: kirim `new EmployeeTermination`). */
    public function save(EmployeeTermination $termination, TerminationRequest $request): EmployeeTermination
    {
        return DB::transaction(function () use ($termination, $request) {
            $payload = $request->payload();

            $termination->fill(['document_scope' => $payload['document_scope'], 'notes' => $payload['notes'] ?: null]);
            if (! $termination->exists) {
                $termination->status = ProposalStatus::Draft;
                $termination->created_by = auth()->id();
            }

            if ($termination->isCollective()) {
                $this->documents->fill($termination, $payload['document'], $payload['document_file'], 'dokumen-penonaktifan');
            } else {
                $this->documents->clear($termination);
            }
            $termination->save();

            $this->saveItems($termination, $payload['items']);

            if ($request->isSubmit()) {
                $this->apply($termination);
            }

            return $termination->refresh();
        });
    }

    /** Pesan sukses sesuai status akhir usulan. */
    public function saveMessage(EmployeeTermination $termination): string
    {
        if ($termination->status === ProposalStatus::Draft) {
            return 'Draf usulan penonaktifan berhasil disimpan.';
        }

        return "Usulan penonaktifan berlaku: {$termination->items()->count()} karyawan nonaktif mulai TMT masing-masing.";
    }

    /** Hapus usulan Draf beserta filenya. */
    public function delete(EmployeeTermination $termination): void
    {
        abort_unless(
            $termination->status === ProposalStatus::Draft,
            409,
            'Hanya usulan berstatus Draf yang bisa dihapus. Usulan Berlaku dibatalkan.',
        );

        DB::transaction(function () use ($termination) {
            $files = [$termination->documentFile, ...$termination->items->map->documentFile];
            $termination->delete();

            foreach ($files as $file) {
                $this->fileStorage->delete($file);
            }
        });
    }

    /** Ganti SATU dokumen usulan Berlaku. `$itemId` kosong = dokumen kolektif; diisi = per orang. */
    public function saveDocument(EmployeeTermination $termination, ?string $itemId, array $input, ?UploadedFile $newFile): EmployeeTermination
    {
        abort_unless(
            $termination->status === ProposalStatus::Effective,
            409,
            'Dokumen dari halaman detail hanya untuk usulan Berlaku. Usulan Draf diubah lewat wizard.',
        );

        $item = $itemId ? $termination->items()->find($itemId) : null;
        abort_if($itemId && $item === null, 404, 'Karyawan tidak ditemukan di usulan ini.');
        $target = $item ?? $termination;

        $values = StatusDocument::read($input);
        $file = $newFile ?? (($input['keep_document_file'] ?? null) === '1' && $target->document_file_id ? true : null);

        $errors = StatusDocument::errorsWithFile($values, $file, required: true);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($termination, $target, $values, $file) {
            $this->documents->fill($target, $values, $file, 'dokumen-penonaktifan');
            $target->save();

            return $termination->refresh();
        });
    }

    /**
     * Batalkan usulan Berlaku: jabatan & atasan bawahan dikembalikan, karyawan Aktif lagi.
     * Ditolak kalau karyawannya sudah DIAKTIFKAN KEMBALI lewat Penambahan sesudahnya.
     */
    public function cancel(EmployeeTermination $termination, ?string $reason): EmployeeTermination
    {
        $reason = trim((string) $reason);

        abort_if($termination->status === ProposalStatus::Draft, 409, 'Usulan berstatus Draf tidak perlu dibatalkan. Hapus saja drafnya.');
        abort_if($termination->status === ProposalStatus::Cancelled, 409, 'Usulan ini sudah dibatalkan.');
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan Pembatalan wajib diisi']);
        }

        $isReactivated = EmployeeActivationItem::query()
            ->whereIn('employee_id', $termination->items()->pluck('employee_id'))
            ->whereHas('activation', fn ($query) => $query
                ->where('status', ProposalStatus::Effective->value)
                ->where('activated_at', '>', $termination->activated_at))
            ->exists();
        abort_if(
            $isReactivated,
            409,
            'Karyawan di usulan ini sudah diaktifkan kembali lewat usulan Penambahan. Batalkan usulan penambahan itu terlebih dahulu.',
        );

        return DB::transaction(function () use ($termination, $reason) {
            $this->revert($termination, $reason);

            $termination->update([
                'status' => ProposalStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancel_reason' => $reason,
            ]);

            $this->refreshEmployees($termination);

            return $termination->refresh();
        });
    }

    /** Usulan BERLAKU: terapkan dampaknya (lihat keterangan kelas). */
    private function apply(EmployeeTermination $termination): void
    {
        $termination->load(['items.employee.user']);
        $items = $termination->items->map(fn (EmployeeTerminationItem $item) => [
            'id' => $item->id,
            'employee_id' => $item->employee_id,
            'effective_date' => $item->effective_date->toDateString(),
        ])->all();

        // 1. Rencana dihitung dari data SEBELUM diubah, lalu bawahan dialihkan.
        $plan = $this->impact->plan($items);

        foreach ($plan['changes'] as $change) {
            $this->supervisorService->replace(
                PositionAssignment::findOrFail($change['assignment_id']),
                $change['to_refs'],
                $termination->supervisorChangeSource(),
                $change['reason'],
                ['termination_id' => $termination->id],
            );
        }

        // 2. Jabatan & keanggotaan regu kerja diakhiri sehari sebelum TMT nonaktif.
        foreach ($termination->items as $item) {
            $endDate = $item->effective_date->copy()->subDay();

            foreach ($plan['assignment_ids'][$item->id] ?? [] as $assignmentId) {
                $assignment = PositionAssignment::findOrFail($assignmentId);
                $item->endedAssignments()->attach($assignment->id, ['previous_end_date' => $assignment->end_date?->toDateString()]);
                $assignment->update(['end_date' => $endDate]);
            }

            foreach ($plan['member_ids'][$item->id] ?? [] as $memberId) {
                $member = WorkGroupMember::findOrFail($memberId);
                $item->endedMemberships()->attach($member->id, ['previous_end_date' => $member->end_date?->toDateString()]);
                // Anggota yang baru akan bergabung setelah TMT: tanggal terakhirnya jatuh sebelum tanggal
                // bergabung (keanggotaannya tidak pernah berjalan).
                $member->update(['end_date' => $endDate]);
            }

            $item->update(['user_deactivated' => $item->employee->user?->is_active === true]);
        }

        // 3. Status kepegawaian & akun login.
        $termination->update(['status' => ProposalStatus::Effective, 'activated_at' => now()]);
        $this->refreshEmployees($termination);
    }

    /** Kembalikan semua dampak usulan Berlaku. */
    private function revert(EmployeeTermination $termination, string $reason): void
    {
        $source = trim('Pembatalan Penonaktifan '.$termination->document_number);
        $ourChanges = $termination->supervisorChanges()
            ->with(['previousSupervisors:id', 'newSupervisors:id'])
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->get();

        // 1. Atasan bawahan dikembalikan (kecuali yang sudah diubah lagi setelahnya).
        foreach ($ourChanges as $change) {
            $assignment = PositionAssignment::find($change->assignment_id);
            $newIds = $change->newSupervisors->pluck('id')->all();

            if ($assignment === null || ! $this->supervisorService->hasSameSupervisors($assignment, $newIds)) {
                continue;
            }

            $this->supervisorService->replace(
                $assignment,
                $change->previousSupervisors->pluck('id')->all(),
                $source,
                $reason,
                ['termination_id' => $termination->id],
            );
        }

        // 2. Jabatan & keanggotaan regu kembali ke tanggal selesai sebelumnya.
        foreach ($termination->items as $item) {
            foreach ($item->endedAssignments as $ended) {
                $ended->update(['end_date' => $ended->pivot->previous_end_date]);
            }
            $item->endedAssignments()->detach();

            foreach ($item->endedMemberships as $ended) {
                $ended->update(['end_date' => $ended->pivot->previous_end_date]);
            }
            $item->endedMemberships()->detach();
        }
    }

    /** Karyawan di usulan: ubah baris lama, tambah baris baru, hapus baris yang tidak dikirim lagi. */
    private function saveItems(EmployeeTermination $termination, array $rows): void
    {
        $existing = $termination->items()->get()->keyBy('id');
        $keptIds = [];

        foreach ($rows as $row) {
            $item = $existing->get($row['id']) ?? new EmployeeTerminationItem(['termination_id' => $termination->id]);
            $type = TerminationType::tryFrom($row['type']);

            $item->fill([
                'employee_id' => $row['employee_id'],
                'type' => $row['type'] ?: null,
                'effective_date' => $row['effective_date'] ?: null,
                'reason' => $row['reason'] ?: null,
                'destination_unit' => $type?->requiresDestinationUnit() ? ($row['destination_unit'] ?: null) : null,
                'notes' => $row['notes'] ?: null,
            ]);

            if ($termination->isCollective()) {
                $this->documents->clear($item);
            } else {
                $this->documents->fill($item, $row['document'], $row['document_file'], 'dokumen-penonaktifan');
            }
            $item->save();
            $keptIds[] = $item->id;
        }

        foreach ($existing->except($keptIds) as $removed) {
            $file = $removed->documentFile;
            $removed->delete();
            $this->fileStorage->delete($file);
        }
    }

    private function refreshEmployees(EmployeeTermination $termination): void
    {
        foreach ($termination->items()->with('employee')->get() as $item) {
            $this->statusService->refresh($item->employee);
        }
    }
}
