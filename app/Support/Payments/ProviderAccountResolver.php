<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Enums\CredentialStatus;
use App\Models\Contract;
use App\Models\PaymentProviderAccount;

/**
 * Blessed traversal for payment credentials (invariant 34):
 * contract → unit → site → legal entity → active provider account.
 */
final class ProviderAccountResolver
{
    /**
     * Resolve the active payment provider account for a contract.
     *
     * @throws PaymentsNotConfigured
     */
    public static function forContract(Contract $contract, string $provider = 'stripe'): PaymentProviderAccount
    {
        $contract->loadMissing(['unitItem.item.site.legalEntity']);

        $entity = $contract->unitItem?->item?->site?->legalEntity;

        if ($entity === null) {
            throw new PaymentsNotConfigured('unknown');
        }

        $account = PaymentProviderAccount::query()
            ->where('legal_entity_id', $entity->id)
            ->where('provider', $provider)
            ->where('is_active', true)
            ->where('status', CredentialStatus::Connected)
            ->first();

        if ($account === null) {
            throw new PaymentsNotConfigured($entity->legal_name, $entity->id);
        }

        return $account;
    }
}
