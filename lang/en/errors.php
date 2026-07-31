<?php

declare(strict_types=1);

return [
    'currency' => [
        'mixed_contract_items' => 'Contract items must all share the same currency.',
        'ledger_mismatch' => 'Ledger row currency must match the contract currency.',
        'allocation_mismatch' => 'Cannot allocate a payment against a charge in a different currency.',
        'rate_junction_mismatch' => 'Price currency does not match the site currency. Pass allow_currency_mismatch to override.',
    ],
    'occupancy' => [
        'unit_occupied' => 'This unit is already occupied for the selected dates.',
    ],
];
