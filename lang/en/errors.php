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
    'contacts' => [
        'invalid_tax_id' => 'The tax ID is invalid for the selected type.',
    ],
    'tax' => [
        'unresolvable_for_jurisdiction' => 'No active tax rate for code ":code" matches jurisdiction :jurisdiction (and no universal fallback exists).',
    ],
    'invoice_series' => [
        'next_number_immutable' => 'next_number cannot be edited after creation. Set starting_number only when creating a series.',
        'identity_frozen' => 'Series code and legal entity cannot change after invoices have been issued under this series.',
        'identity_immutable' => 'Series code, kind, and legal entity cannot be changed after creation.',
        'cannot_archive_sole_default' => 'Cannot archive the default series while no other active series of the same kind exists.',
        'code_in_use' => 'An active series with this code already exists for the legal entity.',
        'kind_mismatch' => 'Invoice series kind is :expected but the invoice requires :given.',
    ],
    'invoices' => [
        'simplified_limit_exceeded' => 'Simplified invoice gross (:gross) exceeds the :limit limit. Complete the contact fiscal data to issue an ordinary invoice.',
        'charges_already_invoiced' => 'One or more charges are already on an invoice.',
        'charge_not_invoicable' => 'One or more charges cannot be invoiced (wrong contract, deposit, refund, or negative adjustment).',
        'missing_default_series' => 'No default :kind invoice series exists for this legal entity.',
        'missing_legal_entity' => 'The contract site has no legal entity assigned.',
        'missing_site' => 'The contract has no unit site to resolve the issuing legal entity.',
        'missing_contact' => 'The contract has no contact for the buyer snapshot.',
        'immutable' => 'Issued invoices cannot be modified or deleted.',
        'rectify_original_not_issued' => 'Only issued invoices can be rectified.',
        'rectify_invalid_reason' => 'Rectification reason is invalid.',
        'rectify_missing_contract' => 'The invoice has no contract to rectify against.',
        'rectify_no_eligible_credits' => 'No eligible uninvoiced credit charges for this invoice (must be negative adjustments on the same contract, not already stamped).',
    ],
    'payments' => [
        'amount_must_be_positive' => 'Payment amount must be greater than zero.',
        'allocation_amount_invalid' => 'Each allocation amount must be greater than zero.',
        'over_allocation_payment' => 'Allocations exceed the payment amount.',
        'over_allocation_charge' => 'Allocation exceeds the charge open amount.',
        'charge_not_on_contract' => 'One or more charges do not belong to this contract.',
        'already_reversed' => 'This payment has already been reversed.',
        'cannot_reverse_reversal' => 'Cannot reverse a reversal payment.',
    ],
    'delinquency' => [
        'revoke_access_reserved' => 'Action "revoke_access" requires access-control integration (S16) and cannot be configured yet.',
        'archive_in_use' => 'Cannot archive delinquency policy assigned to :count site(s). Reassign those sites first.',
        'offset_action_unique' => 'Each (offset_days, action) pair must be unique within a policy.',
        'sort_unique' => 'Each step sort value must be unique within a policy.',
    ],
];

