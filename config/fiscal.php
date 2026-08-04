<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Invoice deposits
    |--------------------------------------------------------------------------
    |
    | Deposit charges are a guarantee, not a supply (gestor #3). Keep false
    | unless the gestor confirms deposits must appear on VAT invoices.
    |
    */
    'invoice_deposits' => (bool) env('FISCAL_INVOICE_DEPOSITS', false),

    /*
    |--------------------------------------------------------------------------
    | Simplified invoice gross limit
    |--------------------------------------------------------------------------
    |
    | Refuse factura simplificada when gross exceeds this amount (EUR string).
    | Gestor #2 may raise the sectoral allowance; do not hardcode a guess of
    | the €3,000 threshold here — keep the conservative €400 default until
    | confirmed in writing.
    |
    */
    'simplified_gross_limit' => env('FISCAL_SIMPLIFIED_GROSS_LIMIT', '400.00'),

    /*
    |--------------------------------------------------------------------------
    | Late-fee tax rate
    |--------------------------------------------------------------------------
    |
    | Percent tax applied when assessing late fees. Default 0% until the
    | gestor confirms late fees are a taxable supply (gestor note, S07).
    |
    */
    'late_fee_tax' => env('FISCAL_LATE_FEE_TAX', '0.00'),

    /*
    |--------------------------------------------------------------------------
    | Invoice late fees
    |--------------------------------------------------------------------------
    |
    | When false, late-fee charges are excluded from VAT invoices (gestor
    | default). Flip only after written confirmation.
    |
    */
    'invoice_late_fees' => (bool) env('FISCAL_INVOICE_LATE_FEES', false),

    /*
    |--------------------------------------------------------------------------
    | Invoice zero-total periods
    |--------------------------------------------------------------------------
    |
    | Free-time discount periods write €0 charges (ledger + cursor) but skip
    | invoice issuance by default (D-DISC #2). Flip true only if the gestor
    | requires a zero-total factura for those periods.
    |
    */
    'invoice_zero_periods' => (bool) env('FISCAL_INVOICE_ZERO_PERIODS', false),

];
