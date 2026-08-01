<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\Contact;
use App\Models\MessageThread;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Outbound thread resolution. Inbound (02) extends the same resolver with
 * References / In-Reply-To evidence.
 */
final class Threading
{
    /**
     * @return array{thread: MessageThread, evidence: array<string, mixed>}
     */
    public static function forOutbound(Contact $contact, Channel $channel, string $subjectOrNumber): array
    {
        return match ($channel) {
            Channel::Email => self::forEmail($contact, $subjectOrNumber),
            Channel::Sms, Channel::Call => self::forNumberKeyed($contact, $channel, $subjectOrNumber),
            Channel::Whatsapp => throw new InvalidArgumentException(
                'WhatsApp thread resolution is not implemented yet.'
            ),
        };
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
     * @return array{thread: MessageThread, evidence: array<string, mixed>}
     */
    private static function forEmail(Contact $contact, string $subject): array
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
