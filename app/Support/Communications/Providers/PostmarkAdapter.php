<?php

declare(strict_types=1);

namespace App\Support\Communications\Providers;

use App\Support\Communications\Channel;
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
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Postmark email adapter. Does not implement AutoRegistersWebhooks — webhooks
 * are created against a Server with a different credential; the panel shows a
 * paste URL instead.
 */
final class PostmarkAdapter implements ProviderAccount, SendsEmail, ReportsDeliveryEvents
{
    private const BASE_URL = 'https://api.postmarkapp.com';

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
        return Provider::Postmark;
    }

    public function channels(): array
    {
        return [Channel::Email];
    }

    public function credentialFields(): array
    {
        return [
            'server_token' => ['label' => 'Server API token', 'secret' => true],
        ];
    }

    public function verify(): VerificationResult
    {
        $response = $this->client()->get(self::BASE_URL.'/server');

        if ($response->failed()) {
            return VerificationResult::failed(
                'Postmark rejected the server token ('.$response->status().').'
            );
        }

        return VerificationResult::ok();
    }

    public function sendEmail(EmailMessage $message): SendResult
    {
        $payload = [
            'From' => $message->from?->formatted() ?? '',
            'To' => implode(',', array_map(
                static fn (EmailAddress $a) => $a->formatted(),
                $message->to
            )),
            'Subject' => $message->subject,
            'HtmlBody' => $message->html,
            'TextBody' => $message->text,
            'MessageStream' => (string) ($this->credentials['message_stream'] ?? 'outbound'),
        ];

        if ($message->replyTo !== null) {
            $payload['ReplyTo'] = $message->replyTo->formatted();
        }

        if ($message->cc !== []) {
            $payload['Cc'] = implode(',', array_map(
                static fn (EmailAddress $a) => $a->formatted(),
                $message->cc
            ));
        }

        if ($message->bcc !== []) {
            $payload['Bcc'] = implode(',', array_map(
                static fn (EmailAddress $a) => $a->formatted(),
                $message->bcc
            ));
        }

        // Postmark accepts a single Tag string; use the first tag.
        if ($message->tags !== []) {
            $payload['Tag'] = $message->tags[0];
        }

        if ($message->headers !== []) {
            $payload['Headers'] = array_map(
                static fn (string $name, string $value) => ['Name' => $name, 'Value' => $value],
                array_keys($message->headers),
                array_values($message->headers)
            );
        }

        if ($message->attachments !== []) {
            $payload['Attachments'] = array_map(
                static fn ($attachment) => [
                    'Name' => $attachment->filename,
                    'Content' => $attachment->base64(),
                    'ContentType' => $attachment->contentType,
                ],
                $message->attachments
            );
        }

        $response = $this->client()->post(self::BASE_URL.'/email', $payload);
        $this->throwIfFailed($response, 'Postmark send');

        /** @var array<string, mixed> $raw */
        $raw = $response->json() ?? [];
        $messageId = (string) ($raw['MessageID'] ?? '');

        return new SendResult($messageId, Provider::Postmark, 0, $raw);
    }

    public function parseDeliveryEvents(array $payload): array
    {
        $rawStatus = (string) ($payload['RecordType'] ?? '');
        $status = match ($rawStatus) {
            'Delivery' => DeliveryStatus::Delivered,
            'Bounce' => DeliveryStatus::Bounced,
            'SpamComplaint' => DeliveryStatus::Spam,
            'Open' => DeliveryStatus::Opened,
            'Click' => DeliveryStatus::Clicked,
            'SubscriptionChange' => DeliveryStatus::Unsubscribed,
            default => null,
        };

        if ($status === null) {
            return [];
        }

        $messageId = (string) ($payload['MessageID'] ?? '');

        if ($messageId === '') {
            return [];
        }

        $occurredAt = isset($payload['ReceivedAt']) && is_string($payload['ReceivedAt'])
            ? CarbonImmutable::parse($payload['ReceivedAt'])
            : (isset($payload['DeliveredAt']) && is_string($payload['DeliveredAt'])
                ? CarbonImmutable::parse($payload['DeliveredAt'])
                : null);

        return [
            new DeliveryEvent(
                providerMessageId: $messageId,
                status: $status,
                rawStatus: $rawStatus,
                occurredAt: $occurredAt,
                recipient: isset($payload['Recipient']) && is_string($payload['Recipient'])
                    ? $payload['Recipient']
                    : (isset($payload['Email']) && is_string($payload['Email']) ? $payload['Email'] : null),
                reason: isset($payload['Description']) && is_string($payload['Description'])
                    ? $payload['Description']
                    : null,
                raw: $payload,
            ),
        ];
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'X-Postmark-Server-Token' => (string) ($this->credentials['server_token'] ?? ''),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])
            ->timeout(15)
            ->retry(2, 200, throw: false);
    }

    private function throwIfFailed(Response $response, string $context): void
    {
        if ($response->failed()) {
            throw ProviderRequestFailed::fromResponse(Provider::Postmark, $response, $context);
        }
    }
}
