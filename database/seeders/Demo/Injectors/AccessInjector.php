<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Injectors;

use App\Jobs\ProcessAccessWebhookEvent;
use App\Models\AccessPoint;
use App\Models\AccessProviderAccount;
use App\Models\AccessWebhookEvent;
use App\Models\Contact;
use Database\Seeders\Demo\DemoWorld;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Fabricates door events and enters at ProcessAccessWebhookEvent.
 */
final class AccessInjector
{
    public function __construct(private readonly DemoWorld $world) {}

    /**
     * @param  'granted'|'denied'  $outcome
     */
    public function doorEvent(
        AccessPoint|string $point,
        string $outcome,
        ?Contact $contact = null,
        ?string $grantRef = null,
        ?string $credentialRef = null,
        ?AccessProviderAccount $account = null,
    ): AccessWebhookEvent {
        if (! in_array($outcome, ['granted', 'denied'], true)) {
            throw new InvalidArgumentException("Door outcome must be granted|denied, got: {$outcome}");
        }

        $account ??= $this->world->accessAccount();
        $providerPointId = $point instanceof AccessPoint
            ? (string) $point->provider_point_id
            : $point;

        $eventId = 'evt_demo_access_'.Str::lower(Str::random(10));
        $payload = [
            'event_id' => $eventId,
            'type' => $outcome,
            'provider_point_id' => $providerPointId,
            'grant_ref' => $grantRef,
            'credential_ref' => $credentialRef ?? ($contact !== null ? 'cred-contact-'.$contact->id : 'cred-demo'),
            'occurred_at' => now()->toIso8601String(),
        ];

        $row = AccessWebhookEvent::query()->create([
            'access_provider_account_id' => $account->id,
            'provider_event_id' => $eventId,
            'payload' => $payload,
            'processing_status' => 'pending',
            'received_at' => now(),
        ]);

        app()->call([new ProcessAccessWebhookEvent($row->id), 'handle']);

        return $row->fresh() ?? $row;
    }
}
