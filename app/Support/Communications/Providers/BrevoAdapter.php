<?php

declare(strict_types=1);

namespace App\Support\Communications\Providers;

use App\Support\Communications\Channel;
use App\Support\Communications\Contracts\AutoRegistersWebhooks;
use App\Support\Communications\Contracts\ProviderAccount;
use App\Support\Communications\Contracts\ReportsDeliveryEvents;
use App\Support\Communications\Contracts\SendsEmail;
use App\Support\Communications\Exceptions\ProviderRequestFailed;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\Provider;
use App\Support\Communications\Results\DeliveryEvent;
use App\Support\Communications\Results\DeliveryStatus;
use App\Support\Communications\Results\SendResult;
use App\Support\Communications\Results\VerificationResult;
use App\Support\Communications\Results\WebhookRegistration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class BrevoAdapter implements ProviderAccount, SendsEmail, AutoRegistersWebhooks, ReportsDeliveryEvents
{
    private const BASE_URL = 'https://api.brevo.com/v3';

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
        return Provider::Brevo;
    }

    public function channels(): array
    {
        return [Channel::Email];
    }

    public function credentialFields(): array
    {
        return [
            'api_key' => ['label' => 'API key', 'secret' => true],
        ];
    }

    public function verify(): VerificationResult
    {
        $response = $this->client()->get(self::BASE_URL.'/account');

        if ($response->failed()) {
            return VerificationResult::failed(
                'Brevo rejected the API key ('.$response->status().').'
            );
        }

        return VerificationResult::ok();
    }

    public function sendEmail(EmailMessage $message): SendResult
    {
        $payload = [
            'sender' => $this->addressObject($message->from),
            'to' => array_map(fn (EmailAddress $a) => $this->addressObject($a), $message->to),
            'subject' => $message->subject,
            'htmlContent' => $message->html,
            'textContent' => $message->text,
        ];

        if ($message->replyTo !== null) {
            $payload['replyTo'] = $this->addressObject($message->replyTo);
        }

        if ($message->cc !== []) {
            $payload['cc'] = array_map(fn (EmailAddress $a) => $this->addressObject($a), $message->cc);
        }

        if ($message->bcc !== []) {
            $payload['bcc'] = array_map(fn (EmailAddress $a) => $this->addressObject($a), $message->bcc);
        }

        if ($message->tags !== []) {
            $payload['tags'] = $message->tags;
        }

        if ($message->headers !== []) {
            $payload['headers'] = $message->headers;
        }

        if ($message->attachments !== []) {
            $payload['attachment'] = array_map(
                static fn ($attachment) => [
                    'name' => $attachment->filename,
                    'content' => $attachment->base64(),
                ],
                $message->attachments
            );
        }

        $response = $this->client()->post(self::BASE_URL.'/smtp/email', $payload);
        $this->throwIfFailed($response, 'Brevo send');

        /** @var array<string, mixed> $raw */
        $raw = $response->json() ?? [];
        $messageId = (string) ($raw['messageId'] ?? '');

        return new SendResult($messageId, Provider::Brevo, 0, $raw);
    }

    public function createWebhook(string $url, array $events): WebhookRegistration
    {
        $response = $this->client()->post(self::BASE_URL.'/webhooks', [
            'url' => $url,
            'type' => 'transactional',
            'events' => $events,
        ]);
        $this->throwIfFailed($response, 'Brevo webhook create');

        $id = $response->json('id');

        if ($id === null) {
            throw new ProviderRequestFailed(
                Provider::Brevo,
                $response->status(),
                'Brevo did not return a webhook id.'
            );
        }

        return new WebhookRegistration((string) $id, signingSecret: null);
    }

    public function deleteWebhook(string $endpointId): void
    {
        $response = $this->client()->delete(self::BASE_URL.'/webhooks/'.$endpointId);

        if ($response->status() === 404) {
            return;
        }

        $this->throwIfFailed($response, 'Brevo webhook delete');
    }

    public function defaultWebhookEvents(): array
    {
        return ['delivered', 'hardBounce', 'softBounce', 'spam', 'blocked', 'unsubscribed', 'opened', 'click'];
    }

    public function parseDeliveryEvents(array $payload): array
    {
        $rawStatus = (string) ($payload['event'] ?? '');
        $status = match ($rawStatus) {
            'request', 'deferred' => DeliveryStatus::Queued,
            'sent', 'loadedByProxy' => DeliveryStatus::Sent,
            'delivered' => DeliveryStatus::Delivered,
            'opened', 'uniqueOpened' => DeliveryStatus::Opened,
            'click' => DeliveryStatus::Clicked,
            'hardBounce', 'softBounce' => DeliveryStatus::Bounced,
            'blocked', 'error', 'invalid' => DeliveryStatus::Failed,
            'spam' => DeliveryStatus::Spam,
            'unsubscribed' => DeliveryStatus::Unsubscribed,
            default => null,
        };

        if ($status === null) {
            return [];
        }

        $messageId = (string) ($payload['message-id'] ?? $payload['messageId'] ?? '');

        if ($messageId === '') {
            return [];
        }

        $occurredAt = isset($payload['date']) && is_string($payload['date'])
            ? CarbonImmutable::parse($payload['date'])
            : null;

        return [
            new DeliveryEvent(
                providerMessageId: $messageId,
                status: $status,
                rawStatus: $rawStatus,
                occurredAt: $occurredAt,
                recipient: isset($payload['email']) && is_string($payload['email']) ? $payload['email'] : null,
                reason: isset($payload['reason']) && is_string($payload['reason']) ? $payload['reason'] : null,
                raw: $payload,
            ),
        ];
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders(['api-key' => (string) ($this->credentials['api_key'] ?? '')])
            ->timeout(15)
            ->retry(2, 200, throw: false);
    }

    private function throwIfFailed(Response $response, string $context): void
    {
        if ($response->failed()) {
            throw ProviderRequestFailed::fromResponse(Provider::Brevo, $response, $context);
        }
    }

    /** @return array{email: string, name?: string} */
    private function addressObject(?EmailAddress $address): array
    {
        if ($address === null) {
            return ['email' => ''];
        }

        $out = ['email' => $address->email];

        if ($address->name !== null && $address->name !== '') {
            $out['name'] = $address->name;
        }

        return $out;
    }
}
