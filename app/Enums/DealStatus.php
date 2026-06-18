<?php

namespace App\Enums;

enum DealStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case OfferSent = 'offer_sent';
    case OfferViewed = 'offer_viewed';
    case Negotiating = 'negotiating';
    case ClosedWon = 'closed_won';
    case ClosedLost = 'closed_lost';
    case Unresponsive = 'unresponsive';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::ClosedWon, self::ClosedLost, self::Unresponsive => true,
            default => false,
        };
    }

    /** @return Array<string> */
    public static function terminalValues(): array
    {
        return array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->isTerminal()),
        );
    }

    /** Pursuit still open — contact lifecycle stays at lead. */
    public function isActivePursuit(): bool
    {
        return ! $this->isTerminal() || $this === self::Unresponsive;
    }

    /** @return Array<string> */
    public static function activePursuitValues(): array
    {
        return array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->isActivePursuit()),
        );
    }
}
