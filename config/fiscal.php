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

];
