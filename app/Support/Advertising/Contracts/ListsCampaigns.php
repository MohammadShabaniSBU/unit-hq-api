<?php

declare(strict_types=1);

namespace App\Support\Advertising\Contracts;

use App\Support\Advertising\Results\CampaignRow;

interface ListsCampaigns
{
    /** @return iterable<CampaignRow> */
    public function campaigns(): iterable;
}
