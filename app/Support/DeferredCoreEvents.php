<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Hook points for tier-3 core events whose domain paths are not built yet.
 *
 * Call these from future Stripe webhook / reversal / lien / offer-expiry code:
 *
 * - RecordsActivity::core('offer.expired', $offer) — once, when read-time expiry flips status
 * - RecordsActivity::core('payment.received', $payment, [...]); + Contact double-log
 * - RecordsActivity::core('charge.reversed', $charge, ['reversal_of_charge_id' => ...])
 * - RecordsActivity::core('payment.reversed', $payment, ['reversal_of_payment_id' => ...])
 * - RecordsActivity::core('lien.triggered', $subject, ['charge_ids' => [...]])
 */
final class DeferredCoreEvents
{
    // Intentionally empty — documentation-only until those controllers exist.
}
