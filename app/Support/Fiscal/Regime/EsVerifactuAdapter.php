<?php

declare(strict_types=1);

namespace App\Support\Fiscal\Regime;

use App\Enums\FiscalRegime;
use App\Enums\InvoiceFiscalStatus;
use App\Models\Invoice;
use App\Models\InvoiceFiscalRecord;

/**
 * Seam only — wraps current issuance. Hash chain and AEAT submission are S04.
 */
final class EsVerifactuAdapter implements FiscalRegimeAdapter
{
    public function record(Invoice $invoice): void
    {
        InvoiceFiscalRecord::query()->create([
            'invoice_id' => $invoice->id,
            'regime' => FiscalRegime::Verifactu,
            'payload' => [
                'full_number' => $invoice->full_number,
                'issue_date' => $invoice->issue_date,
            ],
            'hash' => null,
            'prev_hash' => null,
            'status' => InvoiceFiscalStatus::Pending,
            'submitted_at' => null,
            'created_at' => now(),
        ]);
    }
}
