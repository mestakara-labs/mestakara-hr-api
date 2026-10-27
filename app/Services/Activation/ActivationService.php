<?php

namespace App\Services\Activation;

use App\Enums\ActivationType;
use App\Enums\EmployeeActiveStatus;
use App\Enums\ProposalStatus;
use App\Enums\TransferStatus;
use App\Http\Requests\Activation\ActivationRequest;
use App\Models\Employee;
use App\Models\EmployeeActivation;
use App\Models\EmployeeActivationItem;
use App\Models\EmployeeTerminationItem;
use App\Models\PlacementObject;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\SubUnit;
use App\Models\Transfer;
use App\Services\EmployeeStatusService;
use App\Services\FileStorage;
use App\Services\StatusDocumentService;
use App\Support\ExistingIds;
use App\Support\StatusDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ALUR usulan Penambahan Karyawan (semua perubahan data ada di sini, di dalam transaksi):
 *
 *   save()          simpan usulan baru / ubah usulan Draf; action "submit" = langsung Berlaku
 *   addEmployee()   masukkan karyawan BARU (dari form karyawan) ke usulan Draf
 *   delete()        hapus usulan Draf (draf karyawannya tetap ada, Belum Aktif)
 *   saveDocument()  unggah / ganti dokumen usulan Berlaku (kolektif atau per orang)
 *   cancel()        batalkan usulan Berlaku (alasan wajib)
 *
 * BERLAKU: setiap karyawan yang punya rencana penempatan dibuatkan PENUGASAN mulai TMT-nya,
 * lalu status kepegawaiannya diperbarui (Aktif kalau TMT sudah tiba).
 */
class ActivationService
{
    public function __construct(
        private StatusDocumentService $documents,
        private FileStorage $fileStorage,
        private EmployeeStatusService $statusService,
    ) {}

    /** Simpan usulan (baru: kirim `new EmployeeActivation`). */
    public function save(EmployeeActivation $activation, ActivationRequest $request): EmployeeActivation
    {
        return DB::transaction(function () use ($activation, $request) {
            $payload = $request->payload();

            $activation->fill(['document_scope' => $payload['document_scope'], 'notes' => $payload['notes'] ?: null]);
            if (! $activation->exists) {
                $activation->status = ProposalStatus::Draft;
                $activation->created_by = auth()->id();
            }

            // Dokumen kolektif hanya disimpan kalau cakupannya Kolektif.
            if ($activation->isCollective()) {
                $this->documents->fill($activation, $payload['document'], $payload['document_file'], 'dokumen-penambahan');
            } else {
                $this->documents->clear($activation);
            }
            $activation->save();

            $this->saveItems($activation, $payload['items']);

            if ($request->isSubmit()) {
                $this->apply($activation);
            }

            return $activation->refresh();
        });
    }

    /** Pesan sukses sesuai status akhir usulan. */
    public function saveMessage(EmployeeActivation $activation): string
    {
        if ($activation->status === ProposalStatus::Draft) {
            return 'Draf usulan penambahan berhasil disimpan.';
        }

        return "Usulan penambahan berlaku: {$activation->items()->count()} karyawan aktif mulai TMT masing-masing.";
    }

    /**
     * Masukkan karyawan BARU ke usulan Draf (dipanggil saat form karyawan disimpan dengan activation_id).
     * Dasar & TMT bawaan: Penerimaan Baru, TMT = Tanggal Masuk (bisa diubah di langkah 2 wizard).
     */
    public function addEmployee(EmployeeActivation $activation, Employee $employee): void
    {
        abort_unless(
            $activation->status === ProposalStatus::Draft,
            409,
            'Usulan Penambahan tidak ditemukan atau sudah tidak bisa diubah.',
        );

        $activation->items()->create([
            'employee_id' => $employee->id,
            'type' => ActivationType::NewHire->value,
            'effective_date' => $employee->hire_date,
        ]);
    }

    /** Hapus usulan Draf beserta filenya. Draf karyawannya tetap tersimpan (Belum Aktif). */
    public function delete(EmployeeActivation $activation): void
    {
        abort_unless(
            $activation->status === ProposalStatus::Draft,
            409,
            'Hanya usulan berstatus Draf yang bisa dihapus. Usulan Berlaku dibatalkan.',
        );

        DB::transaction(function () use ($activation) {
            $files = [$activation->documentFile, ...$activation->items->map->documentFile];
            $activation->delete();

            foreach ($files as $file) {
                $this->fileStorage->delete($file);
            }
        });
    }

    /**
     * Unggah / ganti SATU dokumen usulan Berlaku. `$itemId` kosong = dokumen kolektif; diisi = per orang.
     * Dokumen penugasan yang dibuat usulan ini ikut diperbarui.
     */
    public function saveDocument(EmployeeActivation $activation, ?string $itemId, array $input, ?UploadedFile $newFile): EmployeeActivation
    {
        abort_unless(
            $activation->status === ProposalStatus::Effective,
            409,
            'Dokumen dari halaman detail hanya untuk usulan Berlaku. Usulan Draf diubah lewat wizard.',
        );

        $item = $itemId ? $activation->items()->find($itemId) : null;
        abort_if($itemId && $item === null, 404, 'Karyawan tidak ditemukan di usulan ini.');
        $target = $item ?? $activation;

        $values = StatusDocument::read($input);
        $file = $newFile ?? (($input['keep_document_file'] ?? null) === '1' && $target->document_file_id ? true : null);

        $errors = StatusDocument::errorsWithFile($values, $file, required: true);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($activation, $item, $target, $values, $file) {
            $this->documents->fill($target, $values, $file, 'dokumen-penambahan');
            $target->save();

            // Penugasan yang dibuat usulan ini memakai dokumen yang sama.
            $affectedItems = $item ? collect([$item]) : $activation->items;
            foreach ($affectedItems as $affected) {
                $affected->newAssignment?->update($this->decreeColumns($target));
            }

            return $activation->refresh();
        });
    }

    /**
     * Batalkan usulan Berlaku: penugasan yang dibuatnya dihapus, status karyawan dikembalikan.
     * Ditolak kalau penugasan itu sudah dimutasi lagi atau sudah menjadi atasan orang lain.
     */
    public function cancel(EmployeeActivation $activation, ?string $reason): EmployeeActivation
    {
        $reason = trim((string) $reason);

        abort_if($activation->status === ProposalStatus::Draft, 409, 'Usulan berstatus Draf tidak perlu dibatalkan. Hapus saja drafnya.');
        abort_if($activation->status === ProposalStatus::Cancelled, 409, 'Usulan ini sudah dibatalkan.');
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan Pembatalan wajib diisi']);
        }

        $createdIds = $activation->items()->whereNotNull('new_assignment_id')->pluck('new_assignment_id')->all();
        $blockedReason = $this->revertBlockedReason($createdIds);
        abort_if($blockedReason !== null, 409, (string) $blockedReason);

        return DB::transaction(function () use ($activation, $reason, $createdIds) {
            PositionAssignment::whereIn('id', $createdIds)->get()->each->delete();

            $activation->update([
                'status' => ProposalStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancel_reason' => $reason,
            ]);

            $this->refreshEmployees($activation);

            return $activation->refresh();
        });
    }

    /**
     * Usulan BERLAKU: buat penugasan dari rencana penempatan, catat penonaktifan terakhir karyawan
     * yang kembali, lalu perbarui status kepegawaian (dan akun login) setiap karyawan.
     */
    private function apply(EmployeeActivation $activation): void
    {
        $activation->load(['items.employee.user', 'items.placementObjects', 'items.supervisors']);

        foreach ($activation->items as $item) {
            $wasInactive = $item->employee->active_status === EmployeeActiveStatus::Inactive->value;

            $item->previous_termination_item_id = $wasInactive ? $this->lastTerminationItemId($item->employee) : null;
            $item->user_reactivated = $wasInactive && $item->employee->user !== null && ! $item->employee->user->is_active;

            if ($item->hasPlacement()) {
                $document = $activation->isCollective() ? $activation : $item;

                $assignment = PositionAssignment::create([
                    'employee_id' => $item->employee_id,
                    'position_id' => $item->position_id,
                    'sub_unit_id' => $item->sub_unit_id,
                    'assignment_status' => $item->assignment_status,
                    'start_date' => $item->effective_date,
                    'end_date' => $item->assignment_end_date,
                    'notes' => "{$item->type} (Penambahan Karyawan)",
                    ...$this->decreeColumns($document),
                ]);
                $assignment->placementObjects()->sync($item->placementObjects->pluck('id'));
                $assignment->supervisors()->sync($item->supervisors->pluck('id'));
                $item->new_assignment_id = $assignment->id;
            }

            $item->save();
        }

        $activation->update(['status' => ProposalStatus::Effective, 'activated_at' => now()]);
        $this->refreshEmployees($activation);
    }

    /**
     * Karyawan di usulan: ubah baris lama, tambah baris baru, hapus baris yang tidak dikirim lagi.
     * Baris baru dari front end ber-id "baru-...".
     */
    private function saveItems(EmployeeActivation $activation, array $rows): void
    {
        $existing = $activation->items()->get()->keyBy('id');
        $keptIds = [];

        foreach ($rows as $row) {
            $item = $existing->get($row['id']) ?? new EmployeeActivationItem(['activation_id' => $activation->id]);
            $hasPlacement = $row['has_placement'];

            // Draf belum diperiksa lengkap: id yang tidak dikenal disimpan kosong (supaya tidak melanggar foreign key).
            $item->fill([
                'employee_id' => $row['employee_id'],
                'type' => $row['type'] ?: null,
                'effective_date' => $row['effective_date'] ?: null,
                'origin_unit' => $row['type'] === ActivationType::TransferIn->value ? ($row['origin_unit'] ?: null) : null,
                'notes' => $row['notes'] ?: null,
                'position_id' => $hasPlacement ? ExistingIds::first(Position::class, $row['position_id']) : null,
                'assignment_status' => $hasPlacement ? ($row['assignment_status'] ?: null) : null,
                'sub_unit_id' => $hasPlacement ? ExistingIds::first(SubUnit::class, $row['sub_unit_id']) : null,
                'assignment_end_date' => $hasPlacement ? ($row['end_date'] ?: null) : null,
                'new_assignment_id' => null,
            ]);

            // Dokumen per orang hanya disimpan kalau cakupannya Per Orang.
            if ($activation->isCollective()) {
                $this->documents->clear($item);
            } else {
                $this->documents->fill($item, $row['document'], $row['document_file'], 'dokumen-penambahan');
            }
            $item->save();

            $item->placementObjects()->sync($hasPlacement ? ExistingIds::of(PlacementObject::class, $row['placement_object_ids']) : []);
            $item->supervisors()->sync($hasPlacement ? ExistingIds::of(PositionAssignment::class, $row['supervisor_assignment_ids']) : []);
            $keptIds[] = $item->id;
        }

        foreach ($existing->except($keptIds) as $removed) {
            $file = $removed->documentFile;
            $removed->delete();
            $this->fileStorage->delete($file);
        }
    }

    /** Alasan pembatalan ditolak, atau null kalau boleh dibatalkan. */
    private function revertBlockedReason(array $createdIds): ?string
    {
        $laterTransfer = Transfer::query()
            ->where('status', '!=', TransferStatus::Cancelled->value)
            ->whereHas('items', fn ($query) => $query->whereIn('from_assignment_id', $createdIds))
            ->first();
        if ($laterTransfer !== null) {
            $number = $laterTransfer->decree_number ?? 'lain (Draf)';

            return "Karyawan di usulan ini sudah dimutasi lewat usulan {$number}. Batalkan usulan itu terlebih dahulu.";
        }

        $subordinate = PositionAssignment::with('employee')->find(PositionAssignment::subordinateIdsOf($createdIds)[0] ?? null);
        if ($subordinate !== null) {
            return "{$subordinate->employee->name} beratasan karyawan dari usulan ini. Ubah atasannya lewat \"Ubah Atasan\" terlebih dahulu.";
        }

        return null;
    }

    /** Kolom dokumen penugasan (decree_*) dari sebuah dokumen usulan / item. */
    private function decreeColumns(EmployeeActivation|EmployeeActivationItem $document): array
    {
        return [
            'decree_type' => $document->document_type,
            'decree_number' => $document->document_number,
            'decree_date' => $document->document_date,
            'decree_file_id' => $document->document_file_id,
        ];
    }

    /** Item penonaktifan Berlaku TERAKHIR karyawan (untuk karyawan yang kembali), atau null. */
    private function lastTerminationItemId(Employee $employee): ?string
    {
        return EmployeeTerminationItem::query()
            ->where('employee_id', $employee->id)
            ->whereHas('termination', fn ($query) => $query->where('status', ProposalStatus::Effective->value))
            ->orderByDesc('effective_date')
            ->value('id');
    }

    private function refreshEmployees(EmployeeActivation $activation): void
    {
        foreach ($activation->items()->with('employee')->get() as $item) {
            $this->statusService->refresh($item->employee);
        }
    }
}
