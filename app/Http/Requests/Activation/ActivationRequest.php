<?php

namespace App\Http\Requests\Activation;

use App\Http\Requests\Proposal\ProposalRequest;
use App\Models\EmployeeActivation;
use App\Services\Activation\ActivationRules;

/**
 * Isian form usulan Penambahan Karyawan (tambah & ubah). Bentuk umumnya di ProposalRequest.
 *
 * Isian khusus setiap item (lihat ActivationItemPayload di front end):
 *   type, effective_date, origin_unit, notes,
 *   has_placement + position_id, assignment_status, sub_unit_id, placement_object_ids[],
 *   supervisor_assignment_ids[], end_date   (rencana penempatan, opsional)
 *
 * Aturan pemeriksaannya di App\Services\Activation\ActivationRules.
 */
class ActivationRequest extends ProposalRequest
{
    public function currentProposal(): ?EmployeeActivation
    {
        return $this->route('employee_activation');
    }

    protected function notEditableMessage(string $status): string
    {
        return "Usulan berstatus {$status} tidak bisa diubah. Unggah dokumen atau batalkan dari halaman detail.";
    }

    protected function itemFields(array $row): array
    {
        return [
            'type' => $this->text($row, 'type'),
            'effective_date' => $this->text($row, 'effective_date'),
            'origin_unit' => $this->text($row, 'origin_unit'),
            'notes' => $this->text($row, 'notes'),
            'has_placement' => filter_var($row['has_placement'] ?? false, FILTER_VALIDATE_BOOL),
            'position_id' => $this->text($row, 'position_id'),
            'assignment_status' => $this->text($row, 'assignment_status'),
            'sub_unit_id' => $this->text($row, 'sub_unit_id'),
            'placement_object_ids' => $this->list($row, 'placement_object_ids'),
            'supervisor_assignment_ids' => $this->list($row, 'supervisor_assignment_ids'),
            'end_date' => $this->text($row, 'end_date'),
        ];
    }

    protected function errors(array $payload): array
    {
        return app(ActivationRules::class)->errors($payload, $this->isSubmit(), $this->currentProposal());
    }
}
