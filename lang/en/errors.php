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
    'holds' => [
        'unit_held' => 'This unit is already held for the selected dates.',
        'reservation_not_manageable' => 'Reservation holds are managed by the reservation lifecycle and cannot be created or released here.',
        'reason_required' => 'A reason is required for this hold type.',
    ],
    'contracts' => [
        'transition_not_allowed' => 'Cannot transition contract from :from to :to.',
        'cancel_with_payments' => 'Cannot cancel a contract that has received payments. End the contract instead.',
        'notice_withdraw_conflict' => 'Cannot withdraw notice: reservation #:reservation_id holds unit :unit from :starts_on.',
        'deposit_exceeds' => 'Deposit deductions may not exceed the deposit amount.',
        'deposit_reason_required' => 'A reason is required for this deposit outcome.',
        'no_open_occupancy' => 'Contract has no open occupancy to update.',
        'transfer_not_allowed' => 'Cannot transfer a contract in status :status.',
        'transfer_same_unit' => 'Destination unit must differ from the current unit.',
        'transfer_no_unit_item' => 'Contract has no open unit item to transfer.',
        'transfer_no_catalogue_price' => 'Destination unit has no current catalogue price.',
    ],
    'legal_entities' => [
        'archive_with_active_sites' => 'Cannot archive legal entity with :count active site(s). Reassign or archive those sites first.',
        'archive_with_invoices' => 'Cannot archive a legal entity that has issued invoices.',
        'identity_frozen' => 'tax_id and country_code cannot change after invoices have been issued under this entity. Create a new entity instead.',
        'fiscal_regime_s04' => 'Fiscal regime ":regime" is not available yet — enable it in sprint S04 (Verifactu).',
        'fiscal_regime_unimplemented' => 'Fiscal regime ":regime" is not implemented.',
        'fiscal_regime_invalid' => 'Fiscal regime ":regime" is not a valid value.',
    ],
];

