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

    /**
     * Purposes returned when a picker filters by this purpose
     * (debt → debt|general, lead → lead|general, others exact).
     *
     * @return list<string>
     */
    public function pickerAllowlist(): array
    {
        return match ($this) {
            self::Debt => [self::Debt->value, self::General->value],
            self::Lead => [self::Lead->value, self::General->value],
            default => [$this->value],
        };
    }
}
