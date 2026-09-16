<?php

declare(strict_types=1);

namespace App\Support\Fiscal\Regime;

use App\Models\Invoice;

interface FiscalRegimeAdapter
{
    public function record(Invoice $invoice): void;
}
