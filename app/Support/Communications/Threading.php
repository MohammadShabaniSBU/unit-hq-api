<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Thread resolution for outbound and inbound messages.
 */
final class Threading
{
    /**
     * @return array{thread: MessageThread, evidence: array<string, mixed>}
     */
    public static function forOutbound(Contact $contact, Channel $channel, string $subjectOrNumber): array
    {
        return match ($channel) {
            Channel::Email => self::forEmailSubject($contact, $subjectOrNumber, markNew: false),
            Channel::Sms, Channel::Call => self::forNumberKeyed($contact, $channel, $subjectOrNumber),
            Channel::Whatsapp => throw new InvalidArgumentException(
                'WhatsApp thread resolution is not implemented yet.'
            ),
        };
    }

    /**
     * Inbound ladder: References/In-Reply-To → subject+contact → new (email);
     * SMS always (contact, number).
     *
     * @param  array<string, string>  $headers
     * @return array{thread: MessageThread, evidence: array<string, mixed>}
     */
    public static function forInbound(
        Contact $contact,
        Channel $channel,
        string $subjectOrNumber,
        array $headers = [],
        bool $ambiguous = false,
    ): array {
        $resolved = match ($channel) {
            Channel::Email => self::forInboundEmail($contact, $subjectOrNumber, $headers),
            Channel::Sms, Channel::Call => self::forNumberKeyed($contact, $channel, $subjectOrNumber),
            Channel::Whatsapp => throw new InvalidArgumentException(
                'WhatsApp thread resolution is not implemented yet.'
            ),
        };

        if ($ambiguous) {
            $resolved['evidence']['ambiguous'] = true;
        }

        return $resolved;
    }

    /**
     * Strip common reply/forward prefixes (EN/ES/FR/DE) and collapse whitespace.
     */
    public static function normalizeSubject(string $subject): string
    {
        $normalized = trim($subject);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        $prefix = '/^(?:(?:re|fw|fwd|tr|aw|sv|vs)\s*:\s*)+/iu';
        do {
            $previous = $normalized;
            $normalized = preg_replace($prefix, '', $normalized) ?? $normalized;
            $normalized = trim($normalized);
        } while ($normalized !== $previous);

        return $normalized;
    }

    /**
     * Normalize Message-ID tokens for comparison (strip angle brackets / whitespace).
     */
    public static function normalizeMessageId(string $id): string
    {
        $id = trim($id);
        $id = trim($id, '<>');

        return strtolower(trim($id));
    }

    /**
     * @return list<string>
     */
    public static function extractReferenceIds(array $headers): array
    {
        $ids = [];

        foreach (['In-Reply-To', 'References'] as $key) {
            $value = $headers[$key] ?? $headers[strtolower($key)] ?? null;
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            if (preg_match_all('/<([^>]+)>/', $value, $matches) > 0) {
                foreach ($matches[1] as $match) {
                    $ids[] = self::normalizeMessageId($match);
                }
            } else {
                foreach (preg_split('/\s+/', $value) ?: [] as $token) {
                    $token = self::normalizeMessageId($token);
                    if ($token !== '') {
                        $ids[] = $token;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{thread: MessageThread, evidence: array<string, mixed>}
     */
    private static function forInboundEmail(Contact $contact, string $subject, array $headers): array
    {
        $referenceIds = self::extractReferenceIds($headers);
        $headerEvidence = [
            'message_id' => $headers['Message-ID'] ?? $headers['MessageID'] ?? null,
            'in_reply_to' => $headers['In-Reply-To'] ?? null,
            'references' => $headers['References'] ?? null,
        ];

        if ($referenceIds !== []) {
            $messages = Message::query()
                ->whereNotNull('provider_message_id')
                ->whereHas('thread', fn ($q) => $q->where('contact_id', $contact->id)->where('channel', Channel::Email))
                ->orderByDesc('id')
                ->get(['id', 'message_thread_id', 'provider_message_id']);

            foreach ($messages as $message) {
                $stored = self::normalizeMessageId((string) $message->provider_message_id);
                if ($stored !== '' && in_array($stored, $referenceIds, true)) {
                    $thread = MessageThread::query()->findOrFail($message->message_thread_id);

                    return [
                        'thread' => $thread,
                        'evidence' => array_merge($headerEvidence, [
                            'strategy' => 'references',
                            'matched_provider_message_id' => $message->provider_message_id,
                            'reference_ids' => $referenceIds,
                        ]),
                    ];
                }
            }
        }

        $subjectResolved = self::forEmailSubject($contact, $subject, markNew: true);
        $subjectResolved['evidence'] = array_merge($headerEvidence, $subjectResolved['evidence']);

        return $subjectResolved;
    }

    /**
     * @return array{thread: MessageThread, evidence: array<string, mixed>}
     */
    private static function forEmailSubject(Contact $contact, string $subject, bool $markNew): array
    {
        $normalized = self::normalizeSubject($subject);
        $evidence = [
            'strategy' => 'subject',
            'subject' => $subject,
            'normalized_subject' => $normalized,
        ];

        $candidates = MessageThread::query()
            ->where('contact_id', $contact->id)
            ->where('channel', Channel::Email)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        foreach ($candidates as $candidate) {
            if (self::normalizeSubject((string) $candidate->subject) === $normalized) {
                return ['thread' => $candidate, 'evidence' => $evidence];
            }
        }

        $thread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Email,
            'subject' => $subject !== '' ? $subject : null,
            'channel_key' => null,
            'last_message_at' => now(),
            'unread_count' => 0,
        ]);

        if ($markNew) {
            $evidence['strategy'] = 'new';
        }

        return ['thread' => $thread, 'evidence' => $evidence];
    }

    /**
     * @return array{thread: MessageThread, evidence: array<string, mixed>}
     */
    private static function forNumberKeyed(Contact $contact, Channel $channel, string $number): array
    {
        $channelKey = trim($number);
        if ($channelKey === '') {
            throw new InvalidArgumentException('channel_key (counterparty number) is required for SMS/call threads.');
        }

        $evidence = [
            'strategy' => 'channel_key',
            'channel_key' => $channelKey,
        ];

        $existing = MessageThread::query()
            ->where('contact_id', $contact->id)
            ->where('channel', $channel)
            ->where('channel_key', $channelKey)
            ->first();

        if ($existing !== null) {
            return ['thread' => $existing, 'evidence' => $evidence];
        }

        try {
            $thread = MessageThread::query()->create([
                'contact_id' => $contact->id,
                'channel' => $channel,
                'subject' => null,
                'channel_key' => $channelKey,
                'last_message_at' => now(),
                'unread_count' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            $thread = MessageThread::query()
                ->where('contact_id', $contact->id)
                ->where('channel', $channel)
                ->where('channel_key', $channelKey)
                ->firstOrFail();
        }

        return ['thread' => $thread, 'evidence' => $evidence];
    }
}
