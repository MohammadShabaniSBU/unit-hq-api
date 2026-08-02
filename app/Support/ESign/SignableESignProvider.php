<?php

declare(strict_types=1);

namespace App\Support\ESign;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Signable adapter — verify API field names against
 * https://developers.signable.app/openapi at implementation time.
 *
 * Auth: HTTP Basic with API key as username and "x" as password.
 * Sandbox vs live is which API key is stored (no mode column).
 */
final class SignableESignProvider implements ESignProvider
{
    public const ANCHOR_TOKEN = '{signature:signer1:Signature}';

    private const BASE_URL = 'https://api.signable.co.uk/v1';

    /** Webhook types we register for the envelope lifecycle. */
    private const WEBHOOK_TYPES = [
        'send-envelope',
        'signed-envelope-complete',
        'envelope-rejected',
        'envelope-expired',
        'envelope-bounced',
    ];

    /** @param  array<string, mixed>  $credentials */
    private function __construct(
        private readonly array $credentials,
    ) {}

    /** @param  array<string, mixed>  $credentials */
    public static function make(array $credentials): self
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
        $response = $this->client()->get(self::BASE_URL.'/envelopes', [
            'offset' => 0,
            'limit' => 1,
        ]);

        if ($response->failed()) {
            throw new ESignVerificationException(
                'Signable rejected the API key ('.$response->status().').'
            );
        }
    }

    public function signatureAnchor(): string
    {
        return self::ANCHOR_TOKEN;
    }

    public function createEnvelope(EnvelopeSpec $spec): EnvelopeRef
    {
        $payload = [
            'envelope_title' => $spec->title,
            'envelope_parties' => [
                [
                    'party_name' => $spec->signer['name'],
                    'party_email' => $spec->signer['email'],
                    'party_role' => 'signer1',
                ],
            ],
            'envelope_documents' => [
                [
                    'document_title' => $spec->title,
                    'document_file_name' => 'contract.pdf',
                    'document_file_content' => base64_encode($spec->pdfBytes),
                ],
            ],
        ];

        if ($spec->metadata !== []) {
            $payload['envelope_meta'] = $spec->metadata;
        }

        if ($spec->expiresAt !== null) {
            $hours = max(1, (int) ceil(($spec->expiresAt->getTimestamp() - time()) / 3600));
            $payload['envelope_auto_expire_hours'] = $hours;
        }

        $response = $this->client()->post(self::BASE_URL.'/envelopes', $payload);

        if ($response->failed()) {
            throw new ESignProviderException(
                'Signable createEnvelope failed ('.$response->status().'): '.$response->body()
            );
        }

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];
        $fingerprint = (string) ($data['envelope_fingerprint'] ?? '');

        if ($fingerprint === '') {
            throw new ESignProviderException('Signable createEnvelope returned no envelope_fingerprint.');
        }

        $signingUrl = null;
        if (isset($data['href']) && is_string($data['href'])) {
            $signingUrl = $data['href'];
        }

        return new EnvelopeRef($fingerprint, $signingUrl);
    }

    public function cancelEnvelope(string $ref): void
    {
        $response = $this->client()->put(self::BASE_URL.'/envelopes/'.rawurlencode($ref).'/cancel');

        if ($response->failed()) {
            throw new ESignProviderException(
                'Signable cancelEnvelope failed ('.$response->status().'): '.$response->body()
            );
        }
    }

    public function downloadSigned(string $ref): SignedResult
    {
        $response = $this->client()->get(self::BASE_URL.'/envelopes/'.rawurlencode($ref));

        if ($response->failed()) {
            throw new ESignProviderException(
                'Signable getEnvelope failed ('.$response->status().'): '.$response->body()
            );
        }

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];
        $url = $data['envelope_signed_pdf'] ?? null;

        if (! is_string($url) || $url === '') {
            $documents = $data['envelope_documents'] ?? [];
            if (is_array($documents) && isset($documents[0]) && is_array($documents[0])) {
                $signed = $documents[0]['document_signed_pdf'] ?? null;
                $url = is_string($signed) ? $signed : null;
            }
        }

        if (! is_string($url) || $url === '') {
            throw new ESignProviderException('Signable envelope has no signed PDF URL.');
        }

        $pdfResponse = Http::timeout(60)->get($url);

        if ($pdfResponse->failed()) {
            throw new ESignProviderException(
                'Failed to download signed PDF ('.$pdfResponse->status().').'
            );
        }

        return new SignedResult($pdfResponse->body(), null);
    }

    public function parseWebhook(array $payload): ESignEvent
    {
        $webhookType = (string) ($payload['webhook_type']
            ?? $payload['type']
            ?? $payload['event']
            ?? 'unknown');

        $envelopeRef = (string) ($payload['envelope_fingerprint']
            ?? $payload['envelope_ref']
            ?? '');

        $normalized = match ($webhookType) {
            'send-envelope' => ESignEvent::TYPE_SENT,
            'envelope-opened', 'viewed-envelope' => ESignEvent::TYPE_VIEWED,
            'signed-envelope-complete', 'signed-envelope' => ESignEvent::TYPE_SIGNED,
            'envelope-rejected', 'envelope-declined' => ESignEvent::TYPE_DECLINED,
            'envelope-expired' => ESignEvent::TYPE_EXPIRED,
            'envelope-bounced' => ESignEvent::TYPE_BOUNCED,
            default => ESignEvent::TYPE_UNKNOWN,
        };

        $eventId = (string) ($payload['webhook_id'] ?? $payload['event_id'] ?? '');
        if ($eventId === '') {
            $eventId = $webhookType.':'.$envelopeRef.':'.md5(json_encode($payload) ?: '');
        }

        $signer = null;
        $party = $payload['envelope_parties'][0] ?? $payload['party'] ?? null;
        if (is_array($party)) {
            $name = $party['party_name'] ?? $party['name'] ?? null;
            $email = $party['party_email'] ?? $party['email'] ?? null;
            $signer = array_filter([
                'name' => is_string($name) ? $name : null,
                'email' => is_string($email) ? $email : null,
            ], static fn ($v) => $v !== null);
            $signer = $signer === [] ? null : $signer;
        }

        $declineReason = isset($payload['decline_reason'])
            ? (string) $payload['decline_reason']
            : (isset($payload['rejection_reason']) ? (string) $payload['rejection_reason'] : null);

        return new ESignEvent(
            providerEventId: $eventId,
            envelopeRef: $envelopeRef,
            type: $normalized,
            occurredAt: now(),
            signer: $signer,
            declineReason: $declineReason,
        );
    }

    public function registerWebhooks(string $webhookUrl): array
    {
        $ids = [];

        foreach (self::WEBHOOK_TYPES as $type) {
            $response = $this->client()->post(self::BASE_URL.'/webhooks', [
                'webhook_url' => $webhookUrl,
                'webhook_type' => $type,
            ]);

            if ($response->failed()) {
                throw new ESignProviderException(
                    'Signable webhook registration failed for '.$type
                    .' ('.$response->status().'): '.$response->body()
                );
            }

            /** @var array<string, mixed> $data */
            $data = $response->json() ?? [];
            $id = $data['webhook_fingerprint']
                ?? $data['webhook_id']
                ?? $data['id']
                ?? null;

            if ($id !== null) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    public function deleteWebhooks(array $endpointIds): void
    {
        foreach ($endpointIds as $id) {
            try {
                $this->client()->delete(self::BASE_URL.'/webhooks/'.rawurlencode($id));
            } catch (\Throwable) {
                // Best-effort.
            }
        }
    }

    private function client(): PendingRequest
    {
        $apiKey = (string) ($this->credentials['api_key'] ?? '');

        return Http::withBasicAuth($apiKey, 'x')
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }
}
