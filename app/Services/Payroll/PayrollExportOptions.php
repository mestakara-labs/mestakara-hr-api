<?php

namespace App\Services\Payroll;

use Illuminate\Support\Carbon;

/**
 * Pilihan cetak ekspor Daftar Upah / Payroll / SPKL (?sign_date & ?show_draft di GET /payroll/export):
 *
 *   signDate   tanggal di SEMUA blok "<tempat>, <tanggal>"; null = tanggal kunci (WIB) kalau dikunci, selain itu hari ini
 *   showDraft  tulisan DRAF di dokumen & "(DRAF)" di nama file; null = DRAF selama periode belum dikunci.
 *              Periode DIKUNCI tidak pernah diberi DRAF walaupun showDraft = true.
 */
class PayrollExportOptions
{
    public function __construct(
        public readonly ?Carbon $signDate = null,
        public readonly ?bool $showDraft = null,
    ) {}

    /** Mis. "02 Oktober 2026". */
    public function signDateText(string $period): string
    {
        return PayrollExportText::signDate($period, $this->signDate);
    }

    public function isDraft(PayrollData $data): bool
    {
        return ! $data->locked && ($this->showDraft ?? true);
    }
}
