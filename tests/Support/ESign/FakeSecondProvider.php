<?php

declare(strict_types=1);

namespace Tests\Support\ESign;

use App\Support\ESign\EnvelopeRef;
use App\Support\ESign\EnvelopeSpec;
use App\Support\ESign\ESignEvent;
use App\Support\ESign\ESignProvider;
use App\Support\ESign\ESignVerificationException;
use App\Support\ESign\SignedResult;

/**
 * Architecture-seam stand-in: proves a second adapter registers and
 * round-trips with zero changes outside adapter + registry.
 */
final class FakeSecondProvider implements ESignProvider
{
    public const PROVIDER_KEY = 'fake_second';

    public const ANCHOR_TOKEN = '{{fake-second-signature}}';

    public const STUB_PDF = '%PDF-1.4 fake-second-signed';

    /** @var array<string, true> */
    private array $envelopes = [];

    /** @param  array<string, mixed>  $credentials */
    public function __construct(
        private readonly array $credentials = [],
    ) {}

    /** @param  array<string, mixed>  $credentials */
    public static function make(array $credentials = []): self
    {
        return new self($credentials);
    }

    public function credentialFields(): array
    {
        return [
            'api_key' => ['label' => 'API key', 'secret' => true],
        ];
    }

    public function verify(): void
    {
        if (($this->credentials['api_key'] ?? '') === '') {
            throw new ESignVerificationException('FakeSecondProvider requires api_key.');
        }
    }

    public function signatureAnchor(): string
    {
        return self::ANCHOR_TOKEN;
    }

    public function createEnvelope(EnvelopeSpec $spec): EnvelopeRef
    {
        $ref = 'fs-env-'.bin2hex(random_bytes(6));
        $this->envelopes[$ref] = true;

        return new EnvelopeRef($ref, 'https://fake-second.test/sign/'.$ref);
    }

    public function cancelEnvelope(string $ref): void
    {
        unset($this->envelopes[$ref]);
    }

    public function downloadSigned(string $ref): SignedResult
    {
        return new SignedResult(self::STUB_PDF, null);
    }

    public function parseWebhook(array $payload): ESignEvent
    {
        $type = (string) ($payload['type'] ?? 'signed');
        $envelopeRef = (string) ($payload['envelope_ref'] ?? '');
        $eventId = (string) ($payload['event_id'] ?? ($type.':'.$envelopeRef));

        return new ESignEvent(
            providerEventId: $eventId,
            envelopeRef: $envelopeRef,
            type: $type === 'signed' ? ESignEvent::TYPE_SIGNED : ESignEvent::TYPE_UNKNOWN,
            occurredAt: now(),
        );
    }

    public function registerWebhooks(string $webhookUrl): array
    {
        return ['fs-wh-1'];
    }

    public function deleteWebhooks(array $endpointIds): void
    {
        // no-op
    }
}
