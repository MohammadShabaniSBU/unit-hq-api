<?php

declare(strict_types=1);

namespace App\Support\Advertising\Results;

use App\Support\Advertising\AdCampaignStatus;

final readonly class CampaignRow
{
    public function __construct(
        public string $externalId,
        public string $name,
        public AdCampaignStatus $status,
        public ?string $channelType,
    ) {}
}
