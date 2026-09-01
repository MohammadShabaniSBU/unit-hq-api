<?php

declare(strict_types=1);

namespace App\Support\Ai\Identity;

use App\Enums\ContactChannelType;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Support\Automation\SubjectChain;
use App\Support\Communications\Channel;
use App\Support\Communications\SendClass;
use App\Support\Communications\SuppressionWriter;

/**
 * Destination is resolved from contact_channels rows that already belong to
 * the contact. The customer never supplies one. Accepting a destination from
 * the conversation would turn this into an attacker-nominated delivery:
 * channel_asserted means "wrote from an address we have on file"; verification
 * means "still controls it".
 */
final class VerificationDestination
{
    /**
     * @return list<ContactChannelType>
     */
    public static function typesFor(?string $preference): array
    {
        return match ($preference) {
            'email' => [ContactChannelType::Email],
            'sms' => [ContactChannelType::Sms, ContactChannelType::Phone],
            default => [ContactChannelType::Sms, ContactChannelType::Phone, ContactChannelType::Email],
        };
    }

    public static function resolve(Contact $contact, ?string $preference): ?ContactChannel
    {
        foreach (self::typesFor($preference) as $type) {
            $channel = SubjectChain::primaryChannel($contact, $type)
                ?? ContactChannel::query()
                    ->where('contact_id', $contact->id)
                    ->where('type', $type)
                    ->orderByDesc('id')
                    ->first();
            if ($channel !== null) {
                return $channel;
            }
        }

        return null;
    }

    public static function deliveryChannel(ContactChannel $channel): Channel
    {
        return $channel->type === ContactChannelType::Email
            ? Channel::Email
            : Channel::Sms;
    }

    public static function preferenceOf(ContactChannel $channel): string
    {
        return $channel->type === ContactChannelType::Email ? 'email' : 'sms';
    }

    public static function isSuppressed(ContactChannel $channel): bool
    {
        return SuppressionWriter::blocks(
            self::deliveryChannel($channel),
            $channel->value,
            SendClass::Transactional,
        ) !== null;
    }
}
