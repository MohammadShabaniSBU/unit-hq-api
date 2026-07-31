<?php

declare(strict_types=1);

return [
    'currency' => [
        'mixed_contract_items' => 'Los conceptos del contrato deben compartir la misma moneda.',
        'ledger_mismatch' => 'La moneda del asiento debe coincidir con la moneda del contrato.',
        'allocation_mismatch' => 'No se puede imputar un pago a un cargo en una moneda distinta.',
        'rate_junction_mismatch' => 'La moneda del precio no coincide con la del sitio. Pase allow_currency_mismatch para anular.',
    ],
    'occupancy' => [
        'unit_occupied' => 'Esta unidad ya está ocupada en las fechas seleccionadas.',
    ],
];
