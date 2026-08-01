<?php

declare(strict_types=1);

namespace App\Support\Communications\Providers;

use App\Support\Communications\Channel;
use App\Support\Communications\Contracts\ProviderAccount;
use App\Support\Communications\Contracts\ReceivesInbound;
use App\Support\Communications\Contracts\ReportsDeliveryEvents;
use App\Support\Communications\Contracts\SendsEmail;
use App\Support\Communications\Exceptions\ProviderRequestFailed;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\Provider;
use App\Support\Communications\Results\DeliveryEvent;
use App\Support\Communications\Results\DeliveryEventId;
use App\Support\Communications\Results\DeliveryStatus;
use App\Support\Communications\Results\InboundAttachment;
use App\Support\Communications\Results\InboundMessage;
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
final class PostmarkAdapter implements ProviderAccount, SendsEmail, ReportsDeliveryEvents, ReceivesInbound
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

    /**
     * Postmark has no stable per-callback event id — derive
     * sha256(MessageID|RecordType|minute-bucket). Bounce permanence from
     * Type/TypeCode (HardBounce / hard); SpamComplaint is always permanent.
     */
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

        $nativeId = isset($payload['ID']) ? (string) $payload['ID'] : '';
        $providerEventId = $nativeId !== ''
            ? 'postmark:'.$nativeId.':'.$rawStatus
            : DeliveryEventId::derive($messageId, $rawStatus, $occurredAt);

        $isPermanent = false;
        if ($rawStatus === 'SpamComplaint') {
            $isPermanent = true;
        } elseif ($rawStatus === 'Bounce') {
            $bounceType = strtolower((string) ($payload['Type'] ?? $payload['BounceType'] ?? ''));
            $isPermanent = str_contains($bounceType, 'hard')
                || (int) ($payload['TypeCode'] ?? 0) === 1;
        }

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
                providerEventId: $providerEventId,
                isPermanent: $isPermanent,
            ),
        ];
    }

    public function parseInbound(array $payload): ?InboundMessage
    {
        // Delivery callbacks carry RecordType; inbound does not.
        if (isset($payload['RecordType']) && is_string($payload['RecordType']) && $payload['RecordType'] !== '') {
            return null;
        }

        $messageId = (string) ($payload['MessageID'] ?? '');
        $from = (string) ($payload['From'] ?? $payload['FromFull']['Email'] ?? '');

        if ($messageId === '' || $from === '') {
            return null;
        }

        $headers = [];
        if (isset($payload['Headers']) && is_array($payload['Headers'])) {
            foreach ($payload['Headers'] as $header) {
                if (! is_array($header)) {
                    continue;
                }
                $name = (string) ($header['Name'] ?? '');
                $value = (string) ($header['Value'] ?? '');
                if ($name !== '') {
                    $headers[$name] = $value;
                }
            }
        }

        if (! isset($headers['Message-ID']) && $messageId !== '') {
            $headers['Message-ID'] = $messageId;
        }

        $autoGenerated = self::detectAutoGenerated($headers);

        $attachments = [];
        if (isset($payload['Attachments']) && is_array($payload['Attachments'])) {
            foreach ($payload['Attachments'] as $attachment) {
                if (! is_array($attachment)) {
                    continue;
                }

                $content = isset($attachment['Content']) && is_string($attachment['Content'])
                    ? $attachment['Content']
                    : null;
                $size = (int) ($attachment['ContentLength'] ?? ($content !== null ? (int) (strlen($content) * 3 / 4) : 0));

                $attachments[] = new InboundAttachment(
                    filename: (string) ($attachment['Name'] ?? 'attachment'),
                    mimeType: (string) ($attachment['ContentType'] ?? 'application/octet-stream'),
                    sizeBytes: max(0, $size),
                    contentBase64: $content,
                );
            }
        }

        $to = (string) ($payload['To'] ?? $payload['OriginalRecipient'] ?? '');
        $occurredAt = isset($payload['Date']) && is_string($payload['Date'])
            ? CarbonImmutable::parse($payload['Date'])
            : CarbonImmutable::now();

        return new InboundMessage(
            providerMessageId: $messageId,
            providerEventId: $messageId,
            channel: Channel::Email,
            from: $from,
            to: $to,
            subject: isset($payload['Subject']) && is_string($payload['Subject']) ? $payload['Subject'] : null,
            bodyText: isset($payload['TextBody']) && is_string($payload['TextBody']) ? $payload['TextBody'] : null,
            bodyHtml: isset($payload['HtmlBody']) && is_string($payload['HtmlBody']) ? $payload['HtmlBody'] : null,
            headers: $headers,
            attachments: $attachments,
            autoGenerated: $autoGenerated,
            occurredAt: $occurredAt,
            raw: $payload,
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function detectAutoGenerated(array $headers): bool
    {
        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            if ($lower === 'auto-submitted' && strtolower(trim($value)) !== 'no') {
                return true;
            }
            if ($lower === 'x-autoreply' || $lower === 'x-autorespond') {
                return true;
            }
        }

        return false;
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
