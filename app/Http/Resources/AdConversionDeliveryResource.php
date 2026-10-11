<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AdConversionDelivery;
use Illuminate\Http\Request;

/**
 * @mixin AdConversionDelivery
 */
class AdConversionDeliveryResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $event = $this->event;
        $contact = $event?->contact;
        $account = $this->account;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'skip_reason' => $this->skip_reason?->value,
            'identifiers_used' => $this->identifiers_used ?? [],
            'attempts' => $this->attempts,
            'error' => $this->error,
            'marketing_touch_id' => $this->marketing_touch_id,
            'claimed_at' => $this->datetime($this->claimed_at),
            'processed_at' => $this->datetime($this->processed_at),
            'provider_response' => $this->provider_response,
            'account' => $account === null ? null : [
                'id' => $account->id,
                'name' => $account->name,
                'provider' => $account->provider->value,
            ],
            'event' => $event === null ? null : [
                'id' => $event->id,
                'event_key' => $event->event_key->value,
                'dedupe_id' => $event->dedupe_id,
                'occurred_at' => $this->datetime($event->occurred_at),
                'value' => $event->value,
                'currency' => $event->currency,
                'contact' => [
                    'id' => $contact?->id ?? $event->contact_id,
                    'name' => trim(((string) ($contact->first_name ?? '')).' '.((string) ($contact->last_name ?? ''))),
                ],
            ],
        ];
    }
}
