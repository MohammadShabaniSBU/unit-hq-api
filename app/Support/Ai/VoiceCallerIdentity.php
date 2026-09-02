<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Communications\Channel;
use App\Support\Communications\ContactChannelMatcher;

/**
 * Resolve a voice caller to a contact via the inbound phone normaliser.
 * A shared number is not an identification — discard the matcher's
 * newest-activity winner when it reports ambiguous.
 */
final readonly class VoiceCallerIdentity
{
    /** @var list<string> */
    private const WITHHELD = ['anonymous', 'withheld', 'restricted', 'unknown', 'private'];

    public function __construct(
        public ?int $contactId,
        public VerificationLevel $verification,
        public bool $ambiguous,
        public int $matches,
    ) {}

    public static function resolve(?string $callerNumber): self
    {
        $raw = trim((string) $callerNumber);
        if ($raw === '' || in_array(strtolower($raw), self::WITHHELD, true)) {
            return new self(null, VerificationLevel::Anonymous, false, 0);
        }

        $match = ContactChannelMatcher::match(Channel::Call, $raw);

        if ($match['ambiguous']) {
            return new self(null, VerificationLevel::Anonymous, true, $match['matches']);
        }

        $contact = $match['contact'];
        if ($contact === null) {
            return new self(null, VerificationLevel::Anonymous, false, $match['matches']);
        }

        return new self(
            (int) $contact->id,
            VerificationLevel::ChannelAsserted,
            false,
            $match['matches'],
        );
    }
}
