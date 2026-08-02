<?php

declare(strict_types=1);

namespace App\Support\Communications\Providers;

use App\Support\Communications\Channel;
use App\Support\Communications\Contracts\ProviderAccount;
use App\Support\Communications\Contracts\ReceivesInbound;
use App\Support\Communications\Contracts\ReportsDeliveryEvents;
use App\Support\Communications\Contracts\SendsWhatsApp;
use App\Support\Communications\Exceptions\ProviderRequestFailed;
use App\Support\Communications\Messages\WhatsAppSessionMessage;
use App\Support\Communications\Messages\WhatsAppTemplateMessage;
use App\Support\Communications\Provider;
use App\Support\Communications\Results\DeliveryEvent;
use App\Support\Communications\Results\DeliveryEventId;
use App\Support\Communications\Results\DeliveryStatus;
use App\Support\Communications\Results\InboundMessage;
use App\Support\Communications\Results\SendResult;
use App\Support\Communications\Results\VerificationResult;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Sinch Conversation API adapter for WhatsApp (session text + template send).
 * Separate from SinchAdapter (SMS REST) — different host, auth, and payloads.
 */
final class SinchWhatsAppAdapter implements ProviderAccount, SendsWhatsApp, ReportsDeliveryEvents, ReceivesInbound
{
    private const REGIONS = ['us', 'eu', 'br'];

    /** @param  array<string, mixed>  $credentials */
    private function __construct(
        private readonly array $credentials,
    ) {}

    public static function make(array $credentials): static
    {
        return new self($credentials);
    }

    public function provider(): Provider
    {
        return Provider::Sinch;
    }

    public function channels(): array
    {
        return [Channel::Whatsapp];
    }

    public function credentialFields(): array
    {
        return [
            'project_id' => ['label' => 'Project ID', 'secret' => false],
            'key_id' => ['label' => 'Key ID', 'secret' => false],
            'key_secret' => ['label' => 'Key secret', 'secret' => true],
            'app_id' => ['label' => 'Conversation App ID', 'secret' => false],
            'region' => ['label' => 'Region (us/eu/br)', 'secret' => false],
        ];
    }

    public function verify(): VerificationResult
    {
        $projectId = (string) ($this->credentials['project_id'] ?? '');
        $appId = (string) ($this->credentials['app_id'] ?? '');
        if ($projectId === '' || $appId === '') {
            return VerificationResult::failed('Sinch Conversation project_id and app_id are required.');
        }

        $response = $this->client()->get(
            $this->baseUrl()."/v1/projects/{$projectId}/apps/{$appId}"
        );

        if ($response->failed()) {
            return VerificationResult::failed(
                'Sinch rejected the WhatsApp credentials ('.$response->status().').'
            );
        }

        return VerificationResult::ok();
    }

    public function sendSession(WhatsAppSessionMessage $message): SendResult
    {
        $payload = [
            'app_id' => (string) ($this->credentials['app_id'] ?? ''),
            'recipient' => [
                'identified_by' => [
                    'channel_identities' => [[
                        'channel' => 'WHATSAPP',
                        'identity' => $message->to,
                    ]],
                ],
            ],
            'message' => [
                'text_message' => [
                    'text' => $message->body,
                ],
            ],
        ];

        return $this->sendPayload($payload);
    }

    public function sendTemplate(WhatsAppTemplateMessage $message): SendResult
    {
        $parameters = [];
        foreach ($message->variables as $index => $value) {
            $parameters['body['.($index + 1).']text'] = $value;
        }

        $payload = [
            'app_id' => (string) ($this->credentials['app_id'] ?? ''),
            'recipient' => [
                'identified_by' => [
                    'channel_identities' => [[
                        'channel' => 'WHATSAPP',
                        'identity' => $message->to,
                    ]],
                ],
            ],
            'message' => [
                'template_message' => [
                    'channel_template' => [
                        'WHATSAPP' => [
                            'template_id' => $message->templateName,
                            'language_code' => $message->language,
                            'parameters' => $parameters,
                        ],
                    ],
                ],
            ],
        ];

        return $this->sendPayload($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<DeliveryEvent>
     */
    public function parseDeliveryEvents(array $payload): array
    {
        $message = $payload['message_delivery_report'] ?? $payload['message_delivery_receipt'] ?? null;
        if (! is_array($message)) {
            // Also accept a flattened fixture shape used in tests.
            if (isset($payload['message_id'], $payload['status'])) {
                $message = $payload;
            } else {
                return [];
            }
        }

        $rawStatus = (string) ($message['status'] ?? $message['delivery_status'] ?? '');
        $status = match (strtoupper($rawStatus)) {
            'QUEUED' => DeliveryStatus::Queued,
            'SWITCHING_CHANNEL', 'DISPATCHED' => DeliveryStatus::Sent,
            'DELIVERED' => DeliveryStatus::Delivered,
            'READ' => DeliveryStatus::Read,
            'FAILED', 'REJECTED', 'CANCELLED', 'DELETED' => DeliveryStatus::Failed,
            default => null,
        };

        if ($status === null) {
            return [];
        }

        $messageId = (string) ($message['message_id'] ?? $message['id'] ?? '');
        if ($messageId === '') {
            return [];
        }

        $occurredAt = isset($message['event_time']) && is_string($message['event_time'])
            ? CarbonImmutable::parse($message['event_time'])
            : (isset($message['at']) && is_string($message['at'])
                ? CarbonImmutable::parse($message['at'])
                : CarbonImmutable::now());

        $recipient = null;
        $identities = $message['channel_identity'] ?? $message['recipient'] ?? null;
        if (is_array($identities) && isset($identities['identity']) && is_string($identities['identity'])) {
            $recipient = $identities['identity'];
        } elseif (is_string($identities)) {
            $recipient = $identities;
        } elseif (isset($message['recipient']) && is_string($message['recipient'])) {
            $recipient = $message['recipient'];
        }

        $isPermanent = in_array(strtoupper($rawStatus), ['FAILED', 'REJECTED', 'CANCELLED', 'DELETED'], true);

        return [
            new DeliveryEvent(
                providerMessageId: $messageId,
                status: $status,
                rawStatus: $rawStatus,
                occurredAt: $occurredAt,
                recipient: $recipient,
                reason: isset($message['reason']) && is_array($message['reason'])
                    ? (string) ($message['reason']['description'] ?? $message['reason']['code'] ?? '')
                    : null,
                raw: $payload,
                providerEventId: DeliveryEventId::derive($messageId, $rawStatus, $occurredAt),
                isPermanent: $isPermanent,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseInbound(array $payload): ?InboundMessage
    {
        if ($this->isOptOutEvent($payload)) {
            $from = $this->extractIdentity($payload);
            $eventId = (string) ($payload['event_id'] ?? $payload['id'] ?? '');
            if ($from === '' || $eventId === '') {
                return null;
            }

            return new InboundMessage(
                providerMessageId: $eventId,
                providerEventId: $eventId,
                channel: Channel::Whatsapp,
                from: $from,
                to: (string) ($payload['to'] ?? $payload['app_id'] ?? $this->credentials['app_id'] ?? ''),
                subject: null,
                bodyText: 'STOP',
                bodyHtml: null,
                headers: [],
                attachments: [],
                autoGenerated: true,
                occurredAt: $this->eventTime($payload),
                raw: $payload,
                sourceRef: ['opt_out' => true],
                countsAsUnread: false,
            );
        }

        // Flattened fixture shape used by tests.
        if (($payload['type'] ?? null) === 'whatsapp_mo') {
            $messageId = (string) ($payload['id'] ?? '');
            $from = (string) ($payload['from'] ?? '');
            if ($messageId === '' || $from === '') {
                return null;
            }

            return new InboundMessage(
                providerMessageId: $messageId,
                providerEventId: $messageId,
                channel: Channel::Whatsapp,
                from: $from,
                to: (string) ($payload['to'] ?? ''),
                subject: null,
                bodyText: is_string($payload['body'] ?? null) ? (string) $payload['body'] : '',
                bodyHtml: null,
                headers: [],
                attachments: [],
                autoGenerated: false,
                occurredAt: isset($payload['received_at']) && is_string($payload['received_at'])
                    ? CarbonImmutable::parse($payload['received_at'])
                    : CarbonImmutable::now(),
                raw: $payload,
            );
        }

        $inbound = $payload['message'] ?? null;
        if (! is_array($inbound)) {
            return null;
        }

        $channel = strtoupper((string) (
            $inbound['channel_identity']['channel'] ?? $payload['channel'] ?? ''
        ));
        if ($channel !== '' && $channel !== 'WHATSAPP') {
            return null;
        }

        $messageId = (string) ($inbound['id'] ?? $payload['message_id'] ?? '');
        $from = $this->extractIdentity($payload);
        if ($messageId === '' || $from === '') {
            return null;
        }

        $text = '';
        $contactMessage = $inbound['contact_message'] ?? null;
        if (is_array($contactMessage)
            && isset($contactMessage['text_message']['text'])
            && is_string($contactMessage['text_message']['text'])) {
            $text = $contactMessage['text_message']['text'];
        } elseif (isset($inbound['text_message']['text']) && is_string($inbound['text_message']['text'])) {
            $text = $inbound['text_message']['text'];
        }

        return new InboundMessage(
            providerMessageId: $messageId,
            providerEventId: $messageId,
            channel: Channel::Whatsapp,
            from: $from,
            to: (string) ($payload['app_id'] ?? $this->credentials['app_id'] ?? ''),
            subject: null,
            bodyText: $text,
            bodyHtml: null,
            headers: [],
            attachments: [],
            autoGenerated: false,
            occurredAt: $this->eventTime($payload),
            raw: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function eventTime(array $payload): CarbonImmutable
    {
        if (isset($payload['event_time']) && is_string($payload['event_time'])) {
            return CarbonImmutable::parse($payload['event_time']);
        }

        return CarbonImmutable::now();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendPayload(array $payload): SendResult
    {
        $projectId = (string) ($this->credentials['project_id'] ?? '');
        $response = $this->client()->post(
            $this->baseUrl()."/v1/projects/{$projectId}/messages:send",
            $payload,
        );
        $this->throwIfFailed($response, 'Sinch WhatsApp send');

        /** @var array<string, mixed> $raw */
        $raw = $response->json() ?? [];
        $messageId = (string) ($raw['message_id'] ?? $raw['id'] ?? '');

        return new SendResult($messageId, Provider::Sinch, 0, $raw);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isOptOutEvent(array $payload): bool
    {
        if (($payload['type'] ?? null) === 'whatsapp_opt_out') {
            return true;
        }

        $event = $payload['contact_event'] ?? $payload['opt_in_event'] ?? null;
        if (! is_array($event)) {
            return false;
        }

        $status = strtoupper((string) ($event['status'] ?? $event['opt_in_status'] ?? ''));

        return in_array($status, ['OPT_OUT', 'OPTED_OUT', 'STOP'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractIdentity(array $payload): string
    {
        $identity = $payload['channel_identity']['identity']
            ?? $payload['message']['channel_identity']['identity']
            ?? $payload['contact_event']['channel_identity']['identity']
            ?? $payload['from']
            ?? '';

        return is_string($identity) ? $identity : '';
    }

    private function region(): string
    {
        $region = strtolower((string) ($this->credentials['region'] ?? 'us'));

        return in_array($region, self::REGIONS, true) ? $region : 'us';
    }

    private function baseUrl(): string
    {
        return 'https://'.$this->region().'.conversation.api.sinch.com';
    }

    private function client(): PendingRequest
    {
        $keyId = (string) ($this->credentials['key_id'] ?? '');
        $keySecret = (string) ($this->credentials['key_secret'] ?? '');

        return Http::withBasicAuth($keyId, $keySecret)
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->retry(2, 200, throw: false);
    }

    private function throwIfFailed(Response $response, string $context): void
    {
        if ($response->failed()) {
            throw ProviderRequestFailed::fromResponse(Provider::Sinch, $response, $context);
        }
    }
}
