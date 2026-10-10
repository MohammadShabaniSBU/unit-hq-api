<?php

declare(strict_types=1);

namespace App\Enums;

enum TemplatePurpose: string
{
    case General = 'general';
    case Debt = 'debt';
    case Lead = 'lead';
    case Offer = 'offer';
    case System = 'system';
    case Contract = 'contract';
    case Marketing = 'marketing';
    case Newsletter = 'newsletter';
    case Announcement = 'announcement';

    /**
     * Purposes returned when a picker filters by this purpose.
     * debt → debt|general, lead → lead|general,
     * marketing → marketing|general, newsletter → newsletter|marketing,
     * announcement → announcement only. Every other purpose matches itself.
     *
     * @return list<string>
     */
    public function pickerAllowlist(): array
    {
        return match ($this) {
            self::Debt => [self::Debt->value, self::General->value],
            self::Lead => [self::Lead->value, self::General->value],
            self::Marketing => [self::Marketing->value, self::General->value],
            self::Newsletter => [self::Newsletter->value, self::Marketing->value],
            self::Announcement => [self::Announcement->value],
            default => [$this->value],
        };
    }
}
