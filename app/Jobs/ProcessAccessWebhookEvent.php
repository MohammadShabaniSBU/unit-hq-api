<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AccessEventType;
use App\Enums\AccessGrantState;
use App\Enums\AccessPointType;
use App\Enums\HoldType;
use App\Models\AccessEvent;
use App\Models\AccessGrant;
use App\Models\AccessPoint;
use App\Models\AccessSuspension;
use App\Models\AccessWebhookEvent;
use App\Models\Interaction;
use App\Models\SystemEvent;
use App\Models\UnitHold;
use App\Support\Access\AccessProviderRegistry;
use App\Support\Access\AccessWebhookPayload;
use App\Support\Time\SiteClock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Parse access webhook, persist AccessEvent, optional denied-restriction Interaction.
 */
class ProcessAccessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $accessWebhookEventId) {}

    public function handle(AccessProviderRegistry $registry): void
    {
        $event = AccessWebhookEvent::query()->find($this->accessWebhookEventId);

        if ($event === null || $event->processing_status !== 'pending') {
            return;
        }

        $account = $event->accessProviderAccount;

        if ($account === null) {
            $event->processing_status = 'failed';
            $event->processed_at = now();
            $event->save();

            return;
        }

        try {
            $adapter = $registry->forAccount($account);
            /** @var array<string, mixed> $payload */
            $payload = $event->payload;
            $parsed = $adapter->parseWebhook($payload);

            if (! $parsed->isKnown()) {
                SystemEvent::record('webhook.access.unknown_type', $account, [
                    'account_id' => $account->id,
                    'provider_event_id' => $event->provider_event_id,
                    'type' => $parsed->eventType,
                ]);
            } else {
                $this->persistAccessEvent($account->id, $parsed, $payload);
            }

            $event->processing_status = 'processed';
            $event->processed_at = now();
            $event->save();
        } catch (\Throwable $e) {
            $event->processing_status = 'failed';
            $event->processed_at = now();
            $event->save();

            SystemEvent::record('webhook.access.failed', $account, [
                'account_id' => $account->id,
                'provider_event_id' => $event->provider_event_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function persistAccessEvent(int $accountId, AccessWebhookPayload $parsed, array $raw): void
    {
        $point = null;
        if ($parsed->providerPointId !== null && $parsed->providerPointId !== '') {
            $point = AccessPoint::query()
                ->active()
                ->where('access_provider_account_id', $accountId)
                ->where('provider_point_id', $parsed->providerPointId)
                ->first();
        }

        $grant = null;
        if ($parsed->providerGrantId !== null && $parsed->providerGrantId !== '') {
            $grant = AccessGrant::query()
                ->where('provider_grant_id', $parsed->providerGrantId)
                ->whereIn('state', [
                    AccessGrantState::Applying->value,
                    AccessGrantState::Applied->value,
                    AccessGrantState::Revoking->value,
                ])
                ->first();
        }

        if ($grant === null && $parsed->providerCredentialRef !== null && $parsed->providerCredentialRef !== '') {
            // Credential ref may equal provider_grant_id in some adapters; also match live grants on the point.
            $grantQuery = AccessGrant::query()
                ->whereIn('state', [
                    AccessGrantState::Applying->value,
                    AccessGrantState::Applied->value,
                ]);

            if ($point !== null) {
                $grantQuery->where('access_point_id', $point->id);
            }

            $grant = $grantQuery
                ->where('provider_grant_id', $parsed->providerCredentialRef)
                ->first();
        }

        $contactId = $grant?->contact_id;

        $accessEvent = AccessEvent::query()->create([
            'access_point_id' => $point?->id,
            'contact_id' => $contactId,
            'access_grant_id' => $grant?->id,
            'event_type' => AccessEventType::from($parsed->eventType),
            'occurred_at' => $parsed->occurredAt ?? now(),
            'provider_credential_ref' => $parsed->providerCredentialRef,
            'provider_point_id' => $parsed->providerPointId,
            'raw' => $raw,
            'created_at' => now(),
        ]);

        if ($parsed->eventType === AccessEventType::Denied->value && $contactId !== null) {
            $this->maybeRecordDeniedInteraction($accessEvent, $point, $grant, $contactId);
        }
    }

    private function maybeRecordDeniedInteraction(
        AccessEvent $accessEvent,
        ?AccessPoint $point,
        ?AccessGrant $grant,
        int $contactId,
    ): void {
        $underRestriction = false;

        if ($grant !== null) {
            $underRestriction = AccessSuspension::query()
                ->active()
                ->where('contract_id', $grant->contract_id)
                ->exists();
        }

        if (! $underRestriction && $point !== null && $point->point_type === AccessPointType::UnitDoor && $point->unit_id !== null) {
            $site = $point->site;
            $today = $site !== null
                ? SiteClock::today($site)->format('Y-m-d')
                : now()->toDateString();

            $underRestriction = UnitHold::query()
                ->where('unit_id', $point->unit_id)
                ->whereNull('released_at')
                ->where('hold_type', HoldType::Overlock->value)
                ->where('starts_on', '<=', $today)
                ->where(function (Builder $q) use ($today): void {
                    $q->whereNull('ends_on')
                        ->orWhere('ends_on', '>', $today);
                })
                ->exists();
        }

        if (! $underRestriction) {
            return;
        }

        $label = $point?->label ?? ($accessEvent->provider_point_id ?? 'access point');

        Interaction::query()->create([
            'contact_id' => $contactId,
            'deal_id' => null,
            'channel' => 'other',
            'direction' => 'inbound',
            'occurred_at' => $accessEvent->occurred_at,
            'summary' => 'Access denied at '.$label,
            'content' => 'Access denied at '.$label,
            'metadata' => [
                'type' => 'access_denied',
                'access_event_id' => $accessEvent->id,
                'access_point_id' => $point?->id,
                'access_grant_id' => $grant?->id,
                'provider_point_id' => $accessEvent->provider_point_id,
            ],
        ]);
    }
}
