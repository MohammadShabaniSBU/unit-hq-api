<?php

declare(strict_types=1);

namespace App\Support\Communications\Providers;

use App\Support\Communications\Channel;
use App\Support\Communications\Contracts\ProviderAccount;
use App\Support\Communications\Contracts\ReceivesInbound;
use App\Support\Communications\Contracts\ReportsDeliveryEvents;
use App\Support\Communications\Contracts\SendsSms;
use App\Support\Communications\Exceptions\ProviderRequestFailed;
use App\Support\Communications\Messages\SmsMessage;
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
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * Sinch REST SMS adapter — full peer to Twilio (send + delivery + inbound MO).
 * Does not implement AutoRegistersWebhooks; pasteable URL in Settings.
 */
final class SinchAdapter implements ProviderAccount, SendsSms, ReportsDeliveryEvents, ReceivesInbound
{
    private const REGIONS = ['us', 'eu', 'au', 'br', 'ca'];

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
        return [Channel::Sms];
    }

    public function credentialFields(): array
    {
        return [
            'service_plan_id' => ['label' => 'Service Plan ID', 'secret' => false],
            'api_token' => ['label' => 'API token', 'secret' => true],
            'region' => ['label' => 'Region (us/eu/au/br/ca)', 'secret' => false],
        ];
    }

    public function verify(): VerificationResult
    {
        $planId = (string) ($this->credentials['service_plan_id'] ?? '');
        if ($planId === '') {
            return VerificationResult::failed('Sinch service plan id is required.');
        }

        $response = $this->client()->get($this->baseUrl()."/xms/v1/{$planId}/batches", [
            'page' => 0,
            'page_size' => 1,
        ]);

        if ($response->failed()) {
            return VerificationResult::failed(
                'Sinch rejected the credentials ('.$response->status().').'
            );
        }

        return VerificationResult::ok();
    }

    public function sendSms(SmsMessage $message): SendResult
    {
        $planId = (string) ($this->credentials['service_plan_id'] ?? '');
        $payload = [
            'to' => [$message->to],
            'body' => $message->body,
            'delivery_report' => 'per_recipient',
        ];

        if ($message->from !== null && $message->from !== '') {
            $payload['from'] = $message->from;
        }

        $callback = Config::get('communications.status_callback_url');
        if (is_string($callback) && $callback !== '') {
            $payload['callback_url'] = $callback;
        }

        $response = $this->client()->post(
            $this->baseUrl()."/xms/v1/{$planId}/batches",
            $payload
        );
        $this->throwIfFailed($response, 'Sinch send');

        /** @var array<string, mixed> $raw */
        $raw = $response->json() ?? [];
        $messageId = (string) ($raw['id'] ?? '');

        return new SendResult($messageId, Provider::Sinch, 0, $raw);
    }

    /**
     * Recipient delivery reports lack a stable event id — derive
     * sha256(batch_id|status|minute-bucket). Permanence from final
     * non-delivered statuses.
     *
     * @param  array<string, mixed>  $payload
     * @return list<DeliveryEvent>
     */
    public function parseDeliveryEvents(array $payload): array
    {
        $type = (string) ($payload['type'] ?? '');
        if (! in_array($type, ['recipient_delivery_report_sms', 'recipient_delivery_report_mms'], true)) {
            return [];
        }

        $rawStatus = (string) ($payload['status'] ?? '');
        $status = match (strtolower($rawStatus)) {
            'queued' => DeliveryStatus::Queued,
            'dispatched' => DeliveryStatus::Sent,
            'delivered' => DeliveryStatus::Delivered,
            'aborted', 'cancelled', 'rejected', 'deleted', 'failed', 'expired', 'unknown' => DeliveryStatus::Failed,
            default => null,
        };

        if ($status === null) {
            return [];
        }

        $messageId = (string) ($payload['batch_id'] ?? '');
        if ($messageId === '') {
            return [];
        }

        $occurredAt = isset($payload['at']) && is_string($payload['at'])
            ? CarbonImmutable::parse($payload['at'])
            : CarbonImmutable::now();

        $providerEventId = DeliveryEventId::derive($messageId, $rawStatus, $occurredAt);
        $isPermanent = in_array(strtolower($rawStatus), [
            'aborted', 'cancelled', 'rejected', 'deleted', 'failed', 'expired',
        ], true);

        return [
            new DeliveryEvent(
                providerMessageId: $messageId,
                status: $status,
                rawStatus: $rawStatus,
                occurredAt: $occurredAt,
                recipient: isset($payload['recipient']) && is_string($payload['recipient'])
                    ? $payload['recipient']
                    : null,
                reason: isset($payload['code']) ? 'code '.(string) $payload['code'] : null,
                raw: $payload,
                providerEventId: $providerEventId,
                isPermanent: $isPermanent,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseInbound(array $payload): ?InboundMessage
    {
        $type = (string) ($payload['type'] ?? '');
        if ($type !== 'mo_text' && $type !== 'mo_binary' && $type !== 'mo_media') {
            return null;
        }

        $messageId = (string) ($payload['id'] ?? '');
        $from = (string) ($payload['from'] ?? '');
        if ($messageId === '' || $from === '') {
            return null;
        }

        $occurredAt = isset($payload['received_at']) && is_string($payload['received_at'])
            ? CarbonImmutable::parse($payload['received_at'])
            : CarbonImmutable::now();

        return new InboundMessage(
            providerMessageId: $messageId,
            providerEventId: $messageId,
            channel: Channel::Sms,
            from: $from,
            to: (string) ($payload['to'] ?? ''),
            subject: null,
            bodyText: is_string($payload['body'] ?? null) ? (string) $payload['body'] : '',
            bodyHtml: null,
            headers: [],
            attachments: [],
            autoGenerated: false,
            occurredAt: $occurredAt,
            raw: $payload,
        );
    }

    private function region(): string
    {
        $region = strtolower((string) ($this->credentials['region'] ?? 'us'));

        return in_array($region, self::REGIONS, true) ? $region : 'us';
    }

    private function baseUrl(): string
    {
        return 'https://'.$this->region().'.sms.api.sinch.com';
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) ($this->credentials['api_token'] ?? ''))
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
