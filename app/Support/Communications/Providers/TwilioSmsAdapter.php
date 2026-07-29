<?php

declare(strict_types=1);

namespace App\Support\Communications\Providers;

use App\Support\Communications\Channel;
use App\Support\Communications\Contracts\ProviderAccount;
use App\Support\Communications\Contracts\ReportsDeliveryEvents;
use App\Support\Communications\Contracts\SendsSms;
use App\Support\Communications\Exceptions\ProviderRequestFailed;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\Provider;
use App\Support\Communications\Results\DeliveryEvent;
use App\Support\Communications\Results\DeliveryStatus;
use App\Support\Communications\Results\SendResult;
use App\Support\Communications\Results\VerificationResult;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * Twilio SMS adapter. Does not implement AutoRegistersWebhooks — status
 * callbacks are per messaging service / per message. SMS only.
 */
final class TwilioSmsAdapter implements ProviderAccount, SendsSms, ReportsDeliveryEvents
{
    private const BASE_URL = 'https://api.twilio.com/2010-04-01';

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
        return Provider::Twilio;
    }

    public function channels(): array
    {
        return [Channel::Sms];
    }

    public function credentialFields(): array
    {
        return [
            'account_sid' => ['label' => 'Account SID', 'secret' => false],
            'auth_token' => ['label' => 'Auth token', 'secret' => true],
            'messaging_service_sid' => ['label' => 'Messaging Service SID', 'secret' => false],
        ];
    }

    public function verify(): VerificationResult
    {
        $sid = (string) ($this->credentials['account_sid'] ?? '');
        $response = $this->client()->get(self::BASE_URL."/Accounts/{$sid}.json");

        if ($response->failed()) {
            return VerificationResult::failed(
                'Twilio rejected the credentials ('.$response->status().').'
            );
        }

        return VerificationResult::ok();
    }

    public function sendSms(SmsMessage $message): SendResult
    {
        $sid = (string) ($this->credentials['account_sid'] ?? '');
        $payload = [
            'To' => $message->to,
            'Body' => $message->body,
        ];

        $messagingServiceSid = (string) ($this->credentials['messaging_service_sid'] ?? '');

        if ($messagingServiceSid !== '') {
            $payload['MessagingServiceSid'] = $messagingServiceSid;
        } elseif ($message->from !== null && $message->from !== '') {
            $payload['From'] = $message->from;
        }

        $callback = Config::get('communications.status_callback_url');

        if (is_string($callback) && $callback !== '') {
            $payload['StatusCallback'] = $callback;
        }

        $response = $this->client()->asForm()->post(
            self::BASE_URL."/Accounts/{$sid}/Messages.json",
            $payload
        );
        $this->throwIfFailed($response, 'Twilio send');

        /** @var array<string, mixed> $raw */
        $raw = $response->json() ?? [];
        $messageId = (string) ($raw['sid'] ?? '');

        return new SendResult($messageId, Provider::Twilio, 0, $raw);
    }

    public function parseDeliveryEvents(array $payload): array
    {
        $rawStatus = strtolower((string) ($payload['MessageStatus'] ?? $payload['SmsStatus'] ?? ''));
        $status = match ($rawStatus) {
            'accepted', 'queued', 'sending' => DeliveryStatus::Queued,
            'sent' => DeliveryStatus::Sent,
            'delivered' => DeliveryStatus::Delivered,
            'undelivered', 'failed' => DeliveryStatus::Failed,
            default => null,
        };

        if ($status === null) {
            return [];
        }

        $messageId = (string) ($payload['MessageSid'] ?? $payload['SmsSid'] ?? '');

        if ($messageId === '') {
            return [];
        }

        return [
            new DeliveryEvent(
                providerMessageId: $messageId,
                status: $status,
                rawStatus: $rawStatus,
                occurredAt: CarbonImmutable::now(),
                recipient: isset($payload['To']) && is_string($payload['To']) ? $payload['To'] : null,
                reason: isset($payload['ErrorMessage']) && is_string($payload['ErrorMessage'])
                    ? $payload['ErrorMessage']
                    : null,
                raw: $payload,
            ),
        ];
    }

    private function client(): PendingRequest
    {
        return Http::withBasicAuth(
            (string) ($this->credentials['account_sid'] ?? ''),
            (string) ($this->credentials['auth_token'] ?? '')
        )
            ->timeout(15)
            ->retry(2, 200, throw: false);
    }

    private function throwIfFailed(Response $response, string $context): void
    {
        if ($response->failed()) {
            throw ProviderRequestFailed::fromResponse(Provider::Twilio, $response, $context);
        }
    }
}
