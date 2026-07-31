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
    'holds' => [
        'unit_held' => 'Esta unidad ya tiene una retención en las fechas seleccionadas.',
        'reservation_not_manageable' => 'Las retenciones de reserva se gestionan con el ciclo de vida de la reserva y no se pueden crear ni liberar aquí.',
        'reason_required' => 'Se requiere un motivo para este tipo de retención.',
    ],
    'contracts' => [
        'transition_not_allowed' => 'No se puede cambiar el contrato de :from a :to.',
        'cancel_with_payments' => 'No se puede anular un contrato que ha recibido pagos. Finalícelo en su lugar.',
        'notice_withdraw_conflict' => 'No se puede retirar el preaviso: la reserva #:reservation_id retiene la unidad :unit desde :starts_on.',
        'deposit_exceeds' => 'Las deducciones de la fianza no pueden superar el importe de la fianza.',
        'deposit_reason_required' => 'Se requiere un motivo para este resultado de fianza.',
        'no_open_occupancy' => 'El contrato no tiene una ocupación abierta que actualizar.',
        'transfer_not_allowed' => 'No se puede trasladar un contrato en estado :status.',
        'transfer_same_unit' => 'La unidad de destino debe ser distinta de la unidad actual.',
        'transfer_no_unit_item' => 'El contrato no tiene un concepto de unidad abierto para trasladar.',
        'transfer_no_catalogue_price' => 'La unidad de destino no tiene un precio de catálogo vigente.',
    ],
    'legal_entities' => [
        'archive_with_active_sites' => 'No se puede archivar la entidad jurídica con :count sede(s) activa(s). Reasigne o archive esas sedes primero.',
        'archive_with_invoices' => 'No se puede archivar una entidad jurídica que ha emitido facturas.',
        'identity_frozen' => 'El NIF (tax_id) y el country_code no pueden cambiar después de emitir facturas bajo esta entidad. Cree una entidad nueva.',
        'fiscal_regime_s04' => 'El régimen fiscal ":regime" aún no está disponible — se habilita en el sprint S04 (Verifactu).',
        'fiscal_regime_unimplemented' => 'El régimen fiscal ":regime" no está implementado.',
        'fiscal_regime_invalid' => 'El régimen fiscal ":regime" no es un valor válido.',
    ],
];

