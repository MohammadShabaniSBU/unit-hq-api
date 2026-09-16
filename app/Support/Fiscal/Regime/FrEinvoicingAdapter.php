<?php

declare(strict_types=1);

namespace App\Support\Fiscal\Regime;

use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

final class FrEinvoicingAdapter implements FiscalRegimeAdapter
{
    public function record(Invoice $invoice): void
    {
        Log::warning('fiscal.fr_einvoicing.noop', [
            'invoice_id' => $invoice->id,
        ]);
    }
}
