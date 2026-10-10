<?php

declare(strict_types=1);

namespace App\Enums;

enum CampaignType: string
{
    case Campaign = 'campaign';
    case Newsletter = 'newsletter';
    case Announcement = 'announcement';

    public function templatePurpose(): TemplatePurpose
    {
        return match ($this) {
            self::Campaign => TemplatePurpose::Marketing,
            self::Newsletter => TemplatePurpose::Newsletter,
            self::Announcement => TemplatePurpose::Announcement,
        };
    }

    /**
     * Template purposes a send of this type may pin.
     *
     * @return list<string>
     */
    public function purposeAllowlist(): array
    {
        return $this->templatePurpose()->pickerAllowlist();
    }

    /**
     * S32 may attach a discount to a campaign or newsletter. An announcement
     * is a service notice and must reject that attachment.
     */
    public function allowsPromotion(): bool
    {
        return $this !== self::Announcement;
    }
}
