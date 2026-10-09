<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Support\Communications\Channel;

/**
 * Decoded preference or legacy unsubscribe link.
 * Legacy tokens have no contact, list, or message: all lists, this address.
 */
final readonly class PreferenceClaims
{
    public function __construct(
        public ?int $contactId,
        public Channel $channel,
        public string $address,
        public ?int $listId,
        public ?int $messageId,
        public bool $legacy,
    ) {}
}
