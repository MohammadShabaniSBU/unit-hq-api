<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Injectors;

use App\Jobs\ProcessEsignWebhookEvent;
use App\Models\EsignEnvelope;
use App\Models\EsignProviderAccount;
use App\Models\EsignWebhookEvent;
use Database\Seeders\Demo\DemoWorld;
use Illuminate\Support\Str;

/**
 * Fabricates e-sign events and enters at ProcessEsignWebhookEvent
 * (→ EnvelopeOrchestrator::applyEvent via FakeESignProvider).
 */
final class ESignInjector
{
    public function __construct(private readonly DemoWorld $world) {}

    public function viewed(EsignEnvelope $envelope): EsignWebhookEvent
    {
        return $this->fire($envelope, 'viewed');
    }

    public function signed(EsignEnvelope $envelope): EsignWebhookEvent
    {
        return $this->fire($envelope, 'signed');
    }

    public function declined(EsignEnvelope $envelope, ?string $reason = 'Declined in demo'): EsignWebhookEvent
    {
        return $this->fire($envelope, 'declined', ['decline_reason' => $reason]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function fire(EsignEnvelope $envelope, string $type, array $extra = []): EsignWebhookEvent
    {
        /** @var EsignProviderAccount $account */
        $account = $envelope->esignProviderAccount
            ?? $this->world->esignAccount();

        $eventId = 'evt_demo_esign_'.Str::lower(Str::random(10));
        $payload = array_merge([
            'event_id' => $eventId,
            'type' => $type,
            'envelope_ref' => $envelope->provider_envelope_ref,
        ], $extra);

        $row = EsignWebhookEvent::query()->create([
            'esign_provider_account_id' => $account->id,
            'provider_event_id' => $eventId,
            'payload' => $payload,
            'processing_status' => 'pending',
            'received_at' => now(),
        ]);

        app()->call([new ProcessEsignWebhookEvent($row->id), 'handle']);

        return $row->fresh() ?? $row;
    }
}
