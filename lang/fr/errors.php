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
        'overlock_not_manageable' => 'Les cadenas de délinquance sont gérés via le dossier d’impayé et ne peuvent pas être créés ou libérés ici.',
        'reason_required' => 'Un motif est requis pour ce type de blocage.',
    ],
    'contracts' => [
        'transition_not_allowed' => 'Impossible de faire passer le contrat de :from à :to.',
        'cancel_with_payments' => 'Impossible d’annuler un contrat ayant reçu des paiements. Terminez-le plutôt.',
        'notice_withdraw_conflict' => 'Impossible de retirer le préavis : la réservation #:reservation_id bloque l’unité :unit à partir du :starts_on.',
        'deposit_exceeds' => 'Les retenues sur dépôt ne peuvent pas dépasser le montant du dépôt.',
        'deposit_reason_required' => 'Un motif est requis pour ce règlement de dépôt.',
        'no_open_occupancy' => 'Le contrat n’a pas d’occupation ouverte à mettre à jour.',
        'overlock_pending_release' => 'Libérez le cadenas avant de résilier ce contrat.',
        'transfer_not_allowed' => 'Impossible de transférer un contrat au statut :status.',
        'transfer_same_unit' => 'L’unité de destination doit être différente de l’unité actuelle.',
        'transfer_no_unit_item' => 'Le contrat n’a pas de ligne d’unité ouverte à transférer.',
        'transfer_no_catalogue_price' => 'L’unité de destination n’a pas de prix catalogue courant.',
    ],
    'legal_entities' => [
        'archive_with_active_sites' => 'Impossible d’archiver l’entité juridique avec :count site(s) actif(s). Réaffectez ou archivez ces sites d’abord.',
        'archive_with_invoices' => 'Impossible d’archiver une entité juridique ayant émis des factures.',
        'identity_frozen' => 'Le tax_id et le country_code ne peuvent pas changer après l’émission de factures sous cette entité. Créez une nouvelle entité.',
        'fiscal_regime_s04' => 'Le régime fiscal « :regime » n’est pas encore disponible — activation prévue au sprint S04 (Verifactu).',
        'fiscal_regime_unimplemented' => 'Le régime fiscal « :regime » n’est pas implémenté.',
        'fiscal_regime_invalid' => 'Le régime fiscal « :regime » n’est pas une valeur valide.',
    ],
    'contacts' => [
        'invalid_tax_id' => 'Le numéro fiscal n’est pas valide pour le type sélectionné.',
    ],
    'tax' => [
        'unresolvable_for_jurisdiction' => 'Aucun taux de taxe actif pour le code « :code » ne correspond à la juridiction :jurisdiction (et aucun taux universel n’existe).',
    ],
    'invoice_series' => [
        'next_number_immutable' => 'next_number ne peut pas être modifié après la création. Utilisez starting_number uniquement à la création.',
        'identity_frozen' => 'Le code de série et l’entité juridique ne peuvent pas changer après l’émission de factures sous cette série.',
        'identity_immutable' => 'Le code, le type et l’entité juridique de la série ne peuvent pas être modifiés après la création.',
        'cannot_archive_sole_default' => 'Impossible d’archiver la série par défaut tant qu’aucune autre série active du même type n’existe.',
        'code_in_use' => 'Une série active avec ce code existe déjà pour l’entité juridique.',
        'kind_mismatch' => 'Le type de la série est :expected mais la facture exige :given.',
    ],
    'invoices' => [
        'simplified_limit_exceeded' => 'Le total de la facture simplifiée (:gross) dépasse la limite de :limit. Complétez les données fiscales du contact pour émettre une facture ordinaire.',
        'charges_already_invoiced' => 'Un ou plusieurs frais figurent déjà sur une facture.',
        'charge_not_invoicable' => 'Un ou plusieurs frais ne peuvent pas être facturés (mauvais contrat, dépôt, remboursement ou ajustement négatif).',
        'missing_default_series' => 'Aucune série de facturation par défaut de type :kind n’existe pour cette entité juridique.',
        'missing_legal_entity' => 'Le site du contrat n’a pas d’entité juridique assignée.',
        'missing_site' => 'Le contrat n’a pas de site d’unité pour résoudre l’entité émettrice.',
        'missing_contact' => 'Le contrat n’a pas de contact pour le destinataire de la facture.',
        'immutable' => 'Les factures émises ne peuvent pas être modifiées ni supprimées.',
        'rectify_original_not_issued' => 'Seules les factures émises peuvent être rectifiées.',
        'rectify_invalid_reason' => 'Le motif de rectification n’est pas valide.',
        'rectify_missing_contract' => 'La facture n’a pas de contrat associé pour la rectification.',
        'rectify_no_eligible_credits' => 'Aucun crédit éligible non facturé pour cette facture (ajustements négatifs du même contrat, non encore marqués).',
    ],
    'payments' => [
        'amount_must_be_positive' => 'Le montant du paiement doit être supérieur à zéro.',
        'allocation_amount_invalid' => 'Chaque montant d’imputation doit être supérieur à zéro.',
        'over_allocation_payment' => 'Les imputations dépassent le montant du paiement.',
        'over_allocation_charge' => 'L’imputation dépasse le solde ouvert de la charge.',
        'charge_not_on_contract' => 'Une ou plusieurs charges n’appartiennent pas à ce contrat.',
        'already_reversed' => 'Ce paiement a déjà été annulé.',
        'cannot_reverse_reversal' => 'Impossible d’annuler un paiement d’annulation.',
    ],
    'delinquency' => [
        'revoke_access_reserved' => 'L’action « revoke_access » nécessite l’intégration du contrôle d’accès (S16) et ne peut pas encore être configurée.',
        'archive_in_use' => 'Impossible d’archiver la politique d’impayés assignée à :count site(s). Réassignez ces sites d’abord.',
        'offset_action_unique' => 'Chaque paire (offset_days, action) doit être unique au sein d’une politique.',
        'sort_unique' => 'Chaque valeur de tri (sort) doit être unique au sein d’une politique.',
    ],
];

