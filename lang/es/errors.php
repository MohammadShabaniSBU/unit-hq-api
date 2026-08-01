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
        'overlock_not_manageable' => 'Las sobrecerraduras se gestionan desde el caso de impago y no se pueden crear ni liberar aquí.',
        'reason_required' => 'Se requiere un motivo para este tipo de retención.',
    ],
    'contracts' => [
        'transition_not_allowed' => 'No se puede cambiar el contrato de :from a :to.',
        'cancel_with_payments' => 'No se puede anular un contrato que ha recibido pagos. Finalícelo en su lugar.',
        'notice_withdraw_conflict' => 'No se puede retirar el preaviso: la reserva #:reservation_id retiene la unidad :unit desde :starts_on.',
        'deposit_exceeds' => 'Las deducciones de la fianza no pueden superar el importe de la fianza.',
        'deposit_reason_required' => 'Se requiere un motivo para este resultado de fianza.',
        'no_open_occupancy' => 'El contrato no tiene una ocupación abierta que actualizar.',
        'overlock_pending_release' => 'Libere la sobrecerradura antes de dar de baja este contrato.',
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
    'contacts' => [
        'invalid_tax_id' => 'El NIF/NIE no es válido para el tipo seleccionado.',
    ],
    'tax' => [
        'unresolvable_for_jurisdiction' => 'No hay un tipo impositivo activo para el código ":code" que coincida con la jurisdicción :jurisdiction (y no existe un tipo universal).',
    ],
    'invoice_series' => [
        'next_number_immutable' => 'next_number no se puede editar después de la creación. Use starting_number solo al crear la serie.',
        'identity_frozen' => 'El código de serie y la entidad jurídica no pueden cambiar después de emitir facturas bajo esta serie.',
        'identity_immutable' => 'El código, el tipo y la entidad jurídica de la serie no se pueden cambiar después de la creación.',
        'cannot_archive_sole_default' => 'No se puede archivar la serie predeterminada mientras no exista otra serie activa del mismo tipo.',
        'code_in_use' => 'Ya existe una serie activa con este código para la entidad jurídica.',
        'kind_mismatch' => 'El tipo de la serie es :expected pero la factura requiere :given.',
    ],
    'invoices' => [
        'simplified_limit_exceeded' => 'El total de la factura simplificada (:gross) supera el límite de :limit. Complete los datos fiscales del contacto para emitir una factura ordinaria.',
        'charges_already_invoiced' => 'Uno o más cargos ya están en una factura.',
        'charge_not_invoicable' => 'Uno o más cargos no se pueden facturar (contrato incorrecto, fianza, reembolso o ajuste negativo).',
        'missing_default_series' => 'No existe una serie de facturación predeterminada de tipo :kind para esta entidad jurídica.',
        'missing_legal_entity' => 'La sede del contrato no tiene entidad jurídica asignada.',
        'missing_site' => 'El contrato no tiene sede de unidad para resolver la entidad emisora.',
        'missing_contact' => 'El contrato no tiene contacto para el destinatario de la factura.',
        'immutable' => 'Las facturas emitidas no se pueden modificar ni eliminar.',
        'rectify_original_not_issued' => 'Solo se pueden rectificar facturas emitidas.',
        'rectify_invalid_reason' => 'El motivo de rectificación no es válido.',
        'rectify_missing_contract' => 'La factura no tiene contrato asociado para rectificar.',
        'rectify_no_eligible_credits' => 'No hay cargos de crédito elegibles sin facturar para esta factura (deben ser ajustes negativos del mismo contrato, aún no sellados).',
    ],
    'payments' => [
        'amount_must_be_positive' => 'El importe del pago debe ser mayor que cero.',
        'allocation_amount_invalid' => 'Cada importe de imputación debe ser mayor que cero.',
        'over_allocation_payment' => 'Las imputaciones superan el importe del pago.',
        'over_allocation_charge' => 'La imputación supera el importe pendiente del cargo.',
        'charge_not_on_contract' => 'Uno o más cargos no pertenecen a este contrato.',
        'already_reversed' => 'Este pago ya ha sido anulado.',
        'cannot_reverse_reversal' => 'No se puede anular un pago de anulación.',
    ],
    'delinquency' => [
        'revoke_access_reserved' => 'La acción «revoke_access» requiere integración de control de acceso (S16) y aún no se puede configurar.',
        'archive_in_use' => 'No se puede archivar la política de impagos asignada a :count sede(s). Reasigne esas sedes primero.',
        'offset_action_unique' => 'Cada par (offset_days, action) debe ser único dentro de una política.',
        'sort_unique' => 'Cada valor de orden (sort) debe ser único dentro de una política.',
    ],
];

