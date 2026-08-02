<?php

declare(strict_types=1);

namespace App\Support\ESign;

/**
 * Default / test provider. Full stub round-trip for automated tests.
 */
final class FakeESignProvider implements ESignProvider
{
    public const ANCHOR_TOKEN = '{{signature}}';

    public const STUB_PDF = '%PDF-1.4 fake-signed-document';

    /** @var array<string, array{spec: EnvelopeSpec, cancelled: bool, signed: bool}> */
    private static array $envelopes = [];

    /** @param  array<string, mixed>  $credentials */
    public function __construct(
        private readonly array $credentials = [],
    ) {}

    /** Reset in-memory envelope store (tests). */
    public static function reset(): void
    {
        self::$envelopes = [];
    }

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
        $key = (string) ($this->credentials['api_key'] ?? '');
        if ($key === '' || str_starts_with($key, 'bad_')) {
            throw new ESignVerificationException('Fake provider rejected the API key.');
        }
    }

    public function signatureAnchor(): string
    {
        return self::ANCHOR_TOKEN;
    }

    public function createEnvelope(EnvelopeSpec $spec): EnvelopeRef
    {
        $ref = 'fake-env-'.bin2hex(random_bytes(8));
        self::$envelopes[$ref] = [
            'spec' => $spec,
            'cancelled' => false,
            'signed' => false,
        ];

        return new EnvelopeRef($ref, 'https://fake.esign.test/sign/'.$ref);
    }

    public function cancelEnvelope(string $ref): void
    {
        if (! isset(self::$envelopes[$ref])) {
            throw new ESignProviderException('Unknown envelope: '.$ref);
        }

        self::$envelopes[$ref]['cancelled'] = true;
    }

    public function downloadSigned(string $ref): SignedResult
    {
        if (! isset(self::$envelopes[$ref])) {
            throw new ESignProviderException('Unknown envelope: '.$ref);
        }

        return new SignedResult(self::STUB_PDF, null);
    }

    public function parseWebhook(array $payload): ESignEvent
    {
        $type = (string) ($payload['type'] ?? $payload['event'] ?? 'unknown');
        $envelopeRef = (string) ($payload['envelope_ref'] ?? $payload['envelope_fingerprint'] ?? '');
        $eventId = (string) ($payload['event_id'] ?? '');

        if ($eventId === '') {
            $eventId = $type.':'.$envelopeRef.':'.md5(json_encode($payload) ?: '');
        }

        $normalized = match ($type) {
            'sent', 'send-envelope' => ESignEvent::TYPE_SENT,
            'viewed', 'envelope-opened' => ESignEvent::TYPE_VIEWED,
            'signed', 'signed-envelope-complete' => ESignEvent::TYPE_SIGNED,
            'declined', 'envelope-declined' => ESignEvent::TYPE_DECLINED,
            'expired', 'envelope-expired' => ESignEvent::TYPE_EXPIRED,
            'bounced', 'envelope-bounced' => ESignEvent::TYPE_BOUNCED,
            default => ESignEvent::TYPE_UNKNOWN,
        };

        if ($normalized === ESignEvent::TYPE_SIGNED && $envelopeRef !== '' && isset(self::$envelopes[$envelopeRef])) {
            self::$envelopes[$envelopeRef]['signed'] = true;
        }

        $signer = null;
        if (isset($payload['signer']) && is_array($payload['signer'])) {
            $signer = [
                'name' => isset($payload['signer']['name']) ? (string) $payload['signer']['name'] : null,
                'email' => isset($payload['signer']['email']) ? (string) $payload['signer']['email'] : null,
            ];
            $signer = array_filter($signer, static fn ($v) => $v !== null);
            $signer = $signer === [] ? null : $signer;
        }

        return new ESignEvent(
            providerEventId: $eventId,
            envelopeRef: $envelopeRef,
            type: $normalized,
            occurredAt: now(),
            signer: $signer,
            declineReason: isset($payload['decline_reason']) ? (string) $payload['decline_reason'] : null,
        );
    }

    public function registerWebhooks(string $webhookUrl): array
    {
        return ['fake-wh-1'];
    }

    public function deleteWebhooks(array $endpointIds): void
    {
        // no-op
    }
}
