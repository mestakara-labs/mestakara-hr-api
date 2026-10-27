<?php

namespace App\Http\Requests\Termination;

use App\Http\Requests\Proposal\ProposalRequest;
use App\Models\EmployeeTermination;
use App\Services\Termination\TerminationRules;

/**
 * Isian form usulan Penonaktifan Karyawan (tambah & ubah). Bentuk umumnya di ProposalRequest.
 *
 * Isian khusus setiap item (lihat TerminationItemPayload di front end):
 *   type, effective_date (TMT nonaktif), reason, destination_unit, notes
 *
 * Aturan pemeriksaannya di App\Services\Termination\TerminationRules.
 */
class TerminationRequest extends ProposalRequest
{
    public function currentProposal(): ?EmployeeTermination
    {
        return $this->route('employee_termination');
    }

    protected function notEditableMessage(string $status): string
    {
        return "Usulan berstatus {$status} tidak bisa diubah. Ganti dokumen atau batalkan dari halaman detail.";
    }

    protected function itemFields(array $row): array
    {
        return [
            'type' => $this->text($row, 'type'),
            'effective_date' => $this->text($row, 'effective_date'),
            'reason' => $this->text($row, 'reason'),
            'destination_unit' => $this->text($row, 'destination_unit'),
            'notes' => $this->text($row, 'notes'),
        ];
    }

    protected function errors(array $payload): array
    {
        return app(TerminationRules::class)->errors($payload, $this->isSubmit(), $this->currentProposal());
    }
}
