<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Enums\ContactChannelType;
use App\Models\Contact;
use App\Models\ContactChannel;

/**
 * Match an inbound sender address/number against contact_channels.
 * Never creates contacts — unmatched callers park in triage.
 */
final class ContactChannelMatcher
{
    /**
     * @return array{contact: Contact|null, ambiguous: bool, matches: int}
     */
    public static function match(Channel $channel, string $rawSender): array
    {
        $normalized = self::normalize($channel, $rawSender);
        if ($normalized === '') {
            return ['contact' => null, 'ambiguous' => false, 'matches' => 0];
        }

        $type = match ($channel) {
            Channel::Email => ContactChannelType::Email,
            Channel::Sms, Channel::Call => ContactChannelType::Phone,
            Channel::Whatsapp => ContactChannelType::Whatsapp,
        };

        $candidates = ContactChannel::query()
            ->where('type', $type)
            ->with('contact')
            ->get()
            ->filter(function (ContactChannel $row) use ($channel, $normalized): bool {
                return self::normalize($channel, (string) $row->value) === $normalized;
            })
            ->values();

        if ($candidates->isEmpty()) {
            return ['contact' => null, 'ambiguous' => false, 'matches' => 0];
        }

        $byContact = $candidates
            ->groupBy(fn (ContactChannel $row): int => (int) $row->contact_id)
            ->map(fn ($group) => $group->first()->contact)
            ->filter()
            ->values()
            ->sortByDesc(fn (Contact $contact): array => [
                $contact->updated_at?->getTimestamp() ?? 0,
                $contact->id,
            ])
            ->values();

        $winner = $byContact->first();
        $matchCount = $byContact->count();

        return [
            'contact' => $winner,
            'ambiguous' => $matchCount > 1,
            'matches' => $matchCount,
        ];
    }

    public static function normalize(Channel $channel, string $value): string
    {
        $value = trim($value);

        return match ($channel) {
            Channel::Email => self::normalizeEmail($value),
            Channel::Sms, Channel::Call, Channel::Whatsapp => self::normalizePhone($value),
        };
    }

    public static function normalizeEmail(string $value): string
    {
        $value = trim($value);

        // "Name <email@example.com>" → email@example.com
        if (preg_match('/<([^>]+)>/', $value, $matches) === 1) {
            $value = trim($matches[1]);
        }

        return strtolower($value);
    }

    public static function normalizePhone(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $hasPlus = str_starts_with($value, '+');
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return '';
        }

        return ($hasPlus || strlen($digits) > 10 ? '+' : '').$digits;
    }
}
