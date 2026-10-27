<?php

namespace App\Services\Activation;

use App\Enums\ActivationType;
use App\Enums\DocumentScope;
use App\Enums\EmployeeActiveStatus;
use App\Enums\ProposalStatus;
use App\Models\Employee;
use App\Models\EmployeeActivation;
use App\Models\EmployeeActivationItem;
use App\Services\EmployeeStatusService;
use App\Services\OpenProposals;
use App\Support\PlacementRules;
use App\Support\StatusDocument;
use Illuminate\Support\Carbon;

/**
 * SEMUA PEMERIKSAAN usulan Penambahan Karyawan — sama dengan front end (mock activation-mock.ts).
 *
 *   Draf   : karyawan valid & tidak dobel, file yang dikirim benar
 *   Submit : semua aturan (data karyawan lengkap, dasar penambahan, TMT, dokumen, penempatan)
 *
 * Dokumen penambahan OPSIONAL, tetapi kalau salah satu isiannya diisi maka harus lengkap.
 * Bentuk $payload dijelaskan di ProposalRequest::payload() dan ActivationRequest.
 * Hasilnya [nama isian => pesan]; error per karyawan memakai nama "items.<id item>.<isian>".
 */
class ActivationRules
{
    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(
        private OpenProposals $openProposals,
        private EmployeeStatusService $statusService,
    ) {}

    public function errors(array $payload, bool $isSubmit, ?EmployeeActivation $current): array
    {
        $this->errors = [];
        $scope = DocumentScope::tryFrom($payload['document_scope']);

        if ($scope === null) {
            $this->errors['document_scope'] = 'Pilihan dokumen tidak dikenal';
        }
        if ($isSubmit && $payload['items'] === []) {
            $this->errors['items'] = 'Tambahkan minimal satu karyawan';
        }

        // Dokumen kolektif (opsional). Draf: hanya file yang diperiksa.
        if ($scope === DocumentScope::Collective) {
            $this->addAll('', StatusDocument::errorsWithFile($payload['document'], $payload['document_file'], false, ! $isSubmit));
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
                $this->addAll($prefix, StatusDocument::errorsWithFile($item['document'], $item['document_file'], false, ! $isSubmit));
            }
        }

        return $this->errors;
    }

    /**
     * Karyawan harus ada dan tidak sedang di usulan lain yang belum selesai.
     * Saat submit: belum aktif, belum terjadwal aktif, dan biodatanya lengkap.
     */
    private function employeeError(?Employee $employee, ?EmployeeActivation $current, bool $isSubmit): ?string
    {
        if ($employee === null) {
            return 'Karyawan tidak ditemukan';
        }

        if ($this->openProposals->inDraftActivation($employee->id, $current?->id)) {
            return "{$employee->name} sedang ada di usulan Penambahan lain (Draf)";
        }
        if ($this->openProposals->inDraftTermination($employee->id)) {
            return "{$employee->name} sedang ada di usulan Penonaktifan (Draf)";
        }
        if ($transfer = $this->openProposals->openTransfer($employee->id)) {
            return "{$employee->name} sedang ada di usulan Mutasi {$transfer->type->value} ({$transfer->status->value})";
        }

        if (! $isSubmit) {
            return null;
        }

        if ($this->statusService->statusOn($employee) === EmployeeActiveStatus::Active) {
            return "{$employee->name} sudah aktif";
        }

        // Usulan Berlaku lain yang TMT-nya belum tiba (karyawan Nonaktif boleh punya usulan lama yang sudah lewat).
        $isScheduled = EmployeeActivationItem::query()
            ->where('employee_id', $employee->id)
            ->whereDate('effective_date', '>', Carbon::today())
            ->whereHas('activation', fn ($query) => $query->where('status', ProposalStatus::Effective->value))
            ->when($current, fn ($query) => $query->where('activation_id', '!=', $current->id))
            ->exists();
        if ($isScheduled) {
            return "{$employee->name} sudah terjadwal aktif lewat usulan Penambahan lain";
        }

        $employee->load('educations');
        $missing = $employee->missingFields();
        if ($missing !== []) {
            return "Data {$employee->name} belum lengkap: ".implode(', ', $missing);
        }

        return null;
    }

    /** Pemeriksaan lengkap satu karyawan: dasar penambahan, TMT, unit asal, dan rencana penempatan. */
    private function checkItem(array $item, Employee $employee, string $prefix): void
    {
        $allowedTypes = ActivationType::allowedFor($this->statusService->statusOn($employee)->value);
        $allowedValues = array_map(fn (ActivationType $type) => $type->value, $allowedTypes);

        if ($item['type'] === '') {
            $this->errors["{$prefix}type"] = 'Pilih Dasar Penambahan';
        } elseif (! in_array($item['type'], $allowedValues, true)) {
            $this->errors["{$prefix}type"] = 'Pilih '.implode(' atau ', $allowedValues);
        }

        $inactiveSince = $this->statusService->lastEventDate($employee, isActive: false);
        if ($item['effective_date'] === '') {
            $this->errors["{$prefix}effective_date"] = 'Pilih TMT Aktif';
        } elseif ($employee->hire_date && $item['effective_date'] < $employee->hire_date->toDateString()) {
            $this->errors["{$prefix}effective_date"] = 'TMT Aktif tidak boleh sebelum Tanggal Masuk karyawan';
        } elseif ($inactiveSince && $item['effective_date'] <= $inactiveSince->toDateString()) {
            $this->errors["{$prefix}effective_date"] = 'TMT Aktif harus setelah TMT nonaktif terakhir ('.$inactiveSince->locale('id')->translatedFormat('j F Y').')';
        }

        if ($item['type'] === ActivationType::TransferIn->value && $item['origin_unit'] === '') {
            $this->errors["{$prefix}origin_unit"] = 'Unit Asal wajib diisi';
        }

        if ($item['has_placement']) {
            $this->addAll($prefix, PlacementRules::errors($item, $employee->id, $item['effective_date'], 'TMT Aktif'));
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
