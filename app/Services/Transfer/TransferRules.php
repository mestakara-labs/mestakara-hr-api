<?php

namespace App\Services\Transfer;

use App\Enums\AssignmentPeriod;
use App\Enums\AssignmentStatus;
use App\Enums\DocumentMode;
use App\Enums\EmployeeActiveStatus;
use App\Enums\TransferStatus;
use App\Enums\TransferType;
use App\Models\Employee;
use App\Models\PositionAssignment;
use App\Models\Transfer;
use App\Services\OpenProposals;
use App\Support\PlacementRules;
use App\Support\StatusDocument;
use App\Support\UploadRule;
use Illuminate\Http\UploadedFile;

/**
 * SEMUA PEMERIKSAAN usulan mutasi — sama dengan front end (lib/transfer-rules.ts, mock transfer-mock.ts).
 * Dipakai saat usulan disimpan, dan diulang saat usulan diberlakukan (data bisa berubah sejak disimpan).
 *
 * Isian usulan ($payload) berbentuk (lihat TransferRequest::payload()):
 *
 *   type, effective_date, notes, decree_number, decree_date, document_mode,
 *   signer => [signer_source, signer_assignment_id, signer_name, signer_position, ...],
 *   basis  => isian dokumen dasar (StatusDocument::read dengan awalan "basis_"),
 *   items  => [[id, employee_id, from_assignment_id, position_id, assignment_status, sub_unit_id,
 *               placement_object_ids[], supervisor_assignment_ids[], end_date, notes, restore_subordinates], ...]
 *
 * File: UploadedFile = file baru, true = file lama dipertahankan, null = tidak ada.
 *
 * Hasilnya pesan error per isian. Error per karyawan memakai nama "items.<id item>.<isian>".
 */
class TransferRules
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    public function __construct(private OpenProposals $openProposals) {}

    /**
     * @param  bool  $isSubmit  false = simpan Draf (pemeriksaan ringan), true = simpan & lanjutkan (lengkap)
     * @param  Transfer|null  $current  usulan yang sedang diubah (null saat tambah baru)
     * @return array<string, list<string>>
     */
    public function errors(
        array $payload,
        bool $isSubmit,
        ?Transfer $current,
        UploadedFile|bool|null $signedFile,
        UploadedFile|bool|null $basisFile,
    ): array {
        $this->errors = [];

        // --- Isian bersama ---------------------------------------------------
        if ($payload['type'] === '') {
            $this->add('type', 'Pilih Jenis Mutasi');
        } elseif (TransferType::tryFrom($payload['type']) === null) {
            $this->add('type', 'Jenis Mutasi tidak dikenal');
        }

        if ($payload['items'] === []) {
            $this->add('items', 'Pilih minimal satu karyawan');
        }

        if ($isSubmit) {
            $this->checkDecree($payload, $current, $signedFile);
            $this->checkBasisDocument($payload['basis'], $basisFile);
        } elseif ($basisFile instanceof UploadedFile && $fileError = UploadRule::document()->check($basisFile)) {
            // Draf: dokumen dasar boleh belum lengkap, tapi file yang dikirim tetap harus benar.
            $this->add('basis_document_file', $fileError);
        }

        // --- Per karyawan ----------------------------------------------------
        $employeeIds = array_column($payload['items'], 'employee_id');

        foreach ($payload['items'] as $item) {
            $key = fn (string $field) => "items.{$item['id']}.{$field}";

            if (count(array_keys($employeeIds, $item['employee_id'], true)) > 1) {
                $this->add($key('employee_id'), 'Karyawan ini dipilih lebih dari sekali');

                continue;
            }

            $employeeError = $this->employeeError($item['employee_id'], $current);
            if ($employeeError !== null) {
                $this->add($key('employee_id'), $employeeError);

                continue;
            }

            if ($isSubmit) {
                $this->checkItem($item, $payload, $key);
            }
        }

        return $this->errors;
    }

    /** Isian SK: TMT, tanggal & nomor SK (tidak boleh kembar), penandatangan, cara dokumen, scan SK. */
    private function checkDecree(array $payload, ?Transfer $current, UploadedFile|bool|null $signedFile): void
    {
        if ($payload['effective_date'] === '') {
            $this->add('effective_date', 'Pilih TMT Berlaku');
        }
        if ($payload['decree_date'] === '') {
            $this->add('decree_date', 'Pilih Tanggal SK');
        }

        if ($payload['decree_number'] === '') {
            $this->add('decree_number', 'Nomor SK wajib diisi');
        } else {
            $isUsed = Transfer::query()
                ->where('decree_number', $payload['decree_number'])
                ->where('status', '!=', TransferStatus::Cancelled->value)
                ->when($current, fn ($query) => $query->whereKeyNot($current->id))
                ->exists();

            if ($isUsed) {
                $this->add('decree_number', "Nomor SK \"{$payload['decree_number']}\" sudah dipakai usulan lain");
            }
        }

        // Penandatangan SK wajib: Unit (pejabat yang sedang menjabat) / Kantor Regional / Head Office.
        foreach (StatusDocument::signerErrors($payload['signer'], true) as $field => $message) {
            $this->add($field, $message);
        }

        $mode = DocumentMode::tryFrom($payload['document_mode']);
        if ($payload['document_mode'] === '') {
            $this->add('document_mode', 'Pilih cara menyiapkan dokumen SK');
        } elseif ($mode === null) {
            $this->add('document_mode', 'Cara dokumen tidak dikenal');
        } elseif ($mode === DocumentMode::Upload && ! $signedFile) {
            $this->add('signed_file', 'Unggah scan SK yang sudah ditandatangani');
        }

        if ($signedFile instanceof UploadedFile && $fileError = UploadRule::document()->check($signedFile)) {
            $this->add('signed_file', $fileError);
        }
    }

    /** Dokumen dasar (opsional): aturan StatusDocument, nama isian berawalan "basis_". */
    private function checkBasisDocument(array $basis, UploadedFile|bool|null $basisFile): void
    {
        foreach (StatusDocument::errorsWithFile($basis, $basisFile, required: false) as $field => $message) {
            $this->add("basis_{$field}", $message);
        }
    }

    /**
     * Karyawan harus ada, berstatus AKTIF, dan tidak sedang ada di usulan lain yang belum selesai.
     * Hasilnya pesan error, atau null.
     */
    private function employeeError(string $employeeId, ?Transfer $current): ?string
    {
        if ($employeeId === '') {
            return 'Pilih Karyawan';
        }

        $employee = Employee::find($employeeId);
        if ($employee === null) {
            return 'Karyawan tidak ditemukan';
        }
        if ($employee->active_status !== EmployeeActiveStatus::Active->value) {
            return 'Hanya karyawan Aktif yang bisa dimutasi. Aktifkan dulu lewat Penambahan Karyawan.';
        }
        if ($this->openProposals->inDraftActivation($employeeId)) {
            return 'Karyawan ini sedang ada di usulan Penambahan (Draf)';
        }
        if ($this->openProposals->inDraftTermination($employeeId)) {
            return 'Karyawan ini sedang ada di usulan Penonaktifan (Draf)';
        }

        $openTransfer = $this->openProposals->openTransfer($employeeId, $current?->id);
        if ($openTransfer !== null) {
            // Cth. "usulan Rotasi No. 2A44/P/2026.10.01-031 (Menunggu Dokumen)" atau "usulan Promosi (Draf)"
            $numberText = $openTransfer->decree_number ? " No. {$openTransfer->decree_number}" : '';

            return "Karyawan ini sedang ada di usulan {$openTransfer->type->value}{$numberText} ({$openTransfer->status->value})";
        }

        return null;
    }

    /** Pemeriksaan lengkap satu karyawan (aturan penempatan bersama + aturan khusus mutasi). */
    private function checkItem(array $item, array $payload, \Closure $key): void
    {
        $type = TransferType::tryFrom($payload['type']);

        // Penugasan karyawan ini yang belum selesai (sedang berjalan atau akan datang).
        $current = PositionAssignment::query()
            ->where('employee_id', $item['employee_id'])
            ->get()
            ->filter(fn (PositionAssignment $assignment) => $assignment->period() !== AssignmentPeriod::Finished);
        $from = $current->firstWhere('id', $item['from_assignment_id']);
        /** Penugasan yang TETAP berjalan setelah mutasi (semua, kecuali yang diakhiri). */
        $remaining = $current->reject(fn (PositionAssignment $assignment) => $assignment->id === $item['from_assignment_id']);

        // 1. Penugasan lama
        if ($type === TransferType::InitialPlacement) {
            if ($current->isNotEmpty()) {
                $this->add($key('employee_id'), 'Karyawan ini sudah punya jabatan aktif. Pakai jenis mutasi lain.');
            }
        } elseif ($item['from_assignment_id'] === '') {
            if ($type?->requiresFromAssignment()) {
                $this->add($key('from_assignment_id'), 'Pilih jabatan lama yang diakhiri');
            }
        } elseif ($from === null) {
            $this->add($key('from_assignment_id'), 'Jabatan lama harus penugasan karyawan ini yang masih berjalan');
        } elseif ($payload['effective_date'] !== '' && $payload['effective_date'] <= $from->start_date->toDateString()) {
            $this->add($key('from_assignment_id'), 'TMT Berlaku harus setelah TMT Mulai jabatan lama');
        }

        // 2. Rencana penugasan baru (aturan bersama dengan Penambahan Karyawan)
        $placementErrors = PlacementRules::errors($item, $item['employee_id'], $payload['effective_date'], 'TMT Berlaku');
        foreach ($placementErrors as $field => $message) {
            $this->add($key($field), $message);
        }

        // 3. Aturan khusus mutasi
        if (! isset($placementErrors['position_id']) && $remaining->contains('position_id', $item['position_id'])) {
            $this->add($key('position_id'), 'Karyawan ini masih memegang jabatan yang sama');
        }

        // Hanya satu Definitif yang berjalan per karyawan. (Kalau jabatan lama belum benar, pesan di atas saja yang tampil.)
        $isDefinitive = $item['assignment_status'] === AssignmentStatus::Definitive->value;
        if (
            $isDefinitive && ! $this->has($key('from_assignment_id')) && ! $this->has($key('employee_id'))
            && $remaining->contains('assignment_status', AssignmentStatus::Definitive->value)
        ) {
            $this->add($key('assignment_status'), 'Karyawan ini masih Definitif di jabatan lain. Pilih jabatan itu sebagai jabatan lama yang diakhiri.');
        }
    }

    /** Tambah pesan untuk satu isian. */
    private function add(string $field, string $message): void
    {
        $this->errors[$field] ??= [];
        $this->errors[$field][] = $message;
    }

    private function has(string $field): bool
    {
        return isset($this->errors[$field]);
    }
}
