<?php

declare(strict_types=1);

return [
    'currency' => [
        'mixed_contract_items' => 'Les lignes du contrat doivent partager la même devise.',
        'ledger_mismatch' => 'La devise de l’écriture doit correspondre à celle du contrat.',
        'allocation_mismatch' => 'Impossible d’imputer un paiement à une charge dans une autre devise.',
        'rate_junction_mismatch' => 'La devise du prix ne correspond pas à celle du site. Passez allow_currency_mismatch pour forcer.',
    ],
    'occupancy' => [
        'unit_occupied' => 'Cette unité est déjà occupée pour les dates sélectionnées.',
    ],
];
