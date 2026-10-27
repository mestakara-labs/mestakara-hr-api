<?php

namespace App\Services\Termination;

use App\Enums\DocumentScope;
use App\Enums\EmployeeActiveStatus;
use App\Enums\TerminationType;
use App\Models\Employee;
use App\Models\EmployeeTermination;
use App\Models\PositionAssignment;
use App\Services\EmployeeStatusService;
use App\Services\OpenProposals;
use App\Support\StatusDocument;

/**
 * SEMUA PEMERIKSAAN usulan Penonaktifan Karyawan — sama dengan front end (mock termination-mock.ts),
 * ditambah aturan ERD Bab 5 (jenis Pensiun / Kontrak Berakhir sesuai status karyawan).
 *
 *   Draf   : karyawan valid & tidak dobel, file yang dikirim benar
 *   Submit : karyawan Aktif, jenis, TMT, alasan / unit tujuan, dan dokumen WAJIB
 *
 * Bentuk $payload dijelaskan di ProposalRequest::payload() dan TerminationRequest.
 * Hasilnya [nama isian => pesan]; error per karyawan memakai nama "items.<id item>.<isian>".
 */
class TerminationRules
{
    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(
        private OpenProposals $openProposals,
        private EmployeeStatusService $statusService,
    ) {}

    public function errors(array $payload, bool $isSubmit, ?EmployeeTermination $current): array
    {
        $this->errors = [];
        $scope = DocumentScope::tryFrom($payload['document_scope']);

        if ($scope === null) {
            $this->errors['document_scope'] = 'Pilihan dokumen tidak dikenal';
        }
        if ($isSubmit && $payload['items'] === []) {
            $this->errors['items'] = 'Pilih minimal satu karyawan';
        }

        // Dokumen kolektif WAJIB saat submit. Draf: hanya file yang diperiksa.
        if ($scope === DocumentScope::Collective) {
            $this->addAll('', StatusDocument::errorsWithFile($payload['document'], $payload['document_file'], true, ! $isSubmit));
        }

        $employeeIds = array_column($payload['items'], 'employee_id');

        foreach ($payload['items'] as $item) {
            $prefix = "items.{$item['id']}.";

            if (count(array_keys($employeeIds, $item['employee_id'], true)) > 1) {
                $this->errors["{$prefix}employee_id"] = 'Karyawan ini ada lebih dari sekali di usulan';

                continue;
            }

            $employee = Employee::find($item['employee_id']);
            $employeeError = $this->employeeError($employee, $current, $isSubmit);
            if ($employeeError !== null) {
                $this->errors["{$prefix}employee_id"] = $employeeError;

                continue;
            }

            if ($isSubmit) {
                $this->checkItem($item, $employee, $prefix);
            }

            if ($scope === DocumentScope::PerPerson) {
                $this->addAll($prefix, StatusDocument::errorsWithFile($item['document'], $item['document_file'], true, ! $isSubmit));
            }
        }

        return $this->errors;
    }

    /**
     * Karyawan harus ada dan tidak sedang di usulan lain yang belum selesai.
     * Saat submit: sedang Aktif & belum terjadwal nonaktif.
     */
    private function employeeError(?Employee $employee, ?EmployeeTermination $current, bool $isSubmit): ?string
    {
        if ($employee === null) {
            return 'Karyawan tidak ditemukan';
        }

        if ($this->openProposals->inDraftTermination($employee->id, $current?->id)) {
            return "{$employee->name} sedang ada di usulan Penonaktifan lain (Draf)";
        }
        if ($this->openProposals->inDraftActivation($employee->id)) {
            return "{$employee->name} sedang ada di usulan Penambahan (Draf)";
        }
        if ($transfer = $this->openProposals->openTransfer($employee->id)) {
            return "{$employee->name} sedang ada di usulan Mutasi {$transfer->type->value} ({$transfer->status->value})";
        }

        if (! $isSubmit) {
            return null;
        }

        if ($this->statusService->statusOn($employee) !== EmployeeActiveStatus::Active) {
            return "{$employee->name} tidak sedang Aktif";
        }
        if ($this->statusService->isScheduledInactive($employee)) {
            return "{$employee->name} sudah terjadwal nonaktif lewat usulan Penonaktifan lain";
        }

        return null;
    }

    /** Pemeriksaan lengkap satu karyawan: jenis, TMT, alasan, dan unit tujuan. */
    private function checkItem(array $item, Employee $employee, string $prefix): void
    {
        $type = TerminationType::tryFrom($item['type']);

        if ($item['type'] === '') {
            $this->errors["{$prefix}type"] = 'Pilih Jenis Penonaktifan';
        } elseif ($type === null) {
            $this->errors["{$prefix}type"] = 'Jenis Penonaktifan tidak dikenal';
        } elseif (! $type->allowsEmploymentStatus($employee->employment_status)) {
            $this->errors["{$prefix}type"] = $type === TerminationType::ContractEnd
                ? 'Kontrak Berakhir hanya untuk karyawan PKWT, Casual, atau Daily Worker'
                : "{$type->value} hanya untuk Karyawan Tetap";
        }

        $activeSince = $this->statusService->lastEventDate($employee, isActive: true);
        if ($item['effective_date'] === '') {
            $this->errors["{$prefix}effective_date"] = 'Pilih TMT Nonaktif';
        } elseif ($activeSince && $item['effective_date'] <= $activeSince->toDateString()) {
            $this->errors["{$prefix}effective_date"] = 'TMT Nonaktif harus setelah TMT Aktif karyawan';
        } else {
            // Jabatan yang baru akan dimulai pada / setelah TMT tidak bisa diakhiri.
            $laterAssignment = PositionAssignment::query()
                ->where('employee_id', $employee->id)
                ->whereDate('start_date', '>=', $item['effective_date'])
                ->orderBy('start_date')
                ->first();

            if ($laterAssignment !== null) {
                $date = $laterAssignment->start_date->locale('id')->translatedFormat('j F Y');
                $this->errors["{$prefix}effective_date"] = "Karyawan punya jabatan yang baru mulai {$date}. Pilih TMT setelahnya.";
            }
        }

        if ($type?->requiresReason() && $item['reason'] === '') {
            $this->errors["{$prefix}reason"] = 'Alasan wajib diisi';
        }
        if ($type?->requiresDestinationUnit() && $item['destination_unit'] === '') {
            $this->errors["{$prefix}destination_unit"] = 'Unit Tujuan wajib diisi';
        }
    }

    /** Tambahkan beberapa pesan sekaligus dengan awalan nama isian. */
    private function addAll(string $prefix, array $errors): void
    {
        foreach ($errors as $field => $message) {
            $this->errors[$prefix.$field] = $message;
        }
    }
}
