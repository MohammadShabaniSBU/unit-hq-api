<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\ContractStatus;
use App\Models\Contact;
use App\Models\Contract;

/**
 * Shared eligibility for inbound routing. Sales answers prospects;
 * support answers in-force tenants at the binding site.
 */
final class AgentEligibility
{
    public static function hasInForceContractAtSite(?Contact $contact, ?int $siteId): bool
    {
        if ($contact === null) {
            return false;
        }

        $query = Contract::query()
            ->where('contact_id', $contact->id)
            ->whereIn('status', [
                ContractStatus::Active->value,
                ContractStatus::NoticeGiven->value,
            ]);

        if ($siteId !== null) {
            $query->whereHas('unitItem.item', function ($item) use ($siteId): void {
                $item->where('site_id', $siteId);
            });
        }

        return $query->exists();
    }
}
