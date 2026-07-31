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
    'holds' => [
        'unit_held' => 'Cette unité est déjà bloquée pour les dates sélectionnées.',
        'reservation_not_manageable' => 'Les blocages de réservation sont gérés par le cycle de vie de la réservation et ne peuvent pas être créés ou libérés ici.',
        'reason_required' => 'Un motif est requis pour ce type de blocage.',
    ],
    'contracts' => [
        'transition_not_allowed' => 'Impossible de faire passer le contrat de :from à :to.',
        'cancel_with_payments' => 'Impossible d’annuler un contrat ayant reçu des paiements. Terminez-le plutôt.',
        'notice_withdraw_conflict' => 'Impossible de retirer le préavis : la réservation #:reservation_id bloque l’unité :unit à partir du :starts_on.',
        'deposit_exceeds' => 'Les retenues sur dépôt ne peuvent pas dépasser le montant du dépôt.',
        'deposit_reason_required' => 'Un motif est requis pour ce règlement de dépôt.',
        'no_open_occupancy' => 'Le contrat n’a pas d’occupation ouverte à mettre à jour.',
        'transfer_not_allowed' => 'Impossible de transférer un contrat au statut :status.',
        'transfer_same_unit' => 'L’unité de destination doit être différente de l’unité actuelle.',
        'transfer_no_unit_item' => 'Le contrat n’a pas de ligne d’unité ouverte à transférer.',
        'transfer_no_catalogue_price' => 'L’unité de destination n’a pas de prix catalogue courant.',
    ],
];

