<?php

declare(strict_types=1);

namespace App\Support\Advertising\Contracts;

use App\Support\Advertising\Results\ConversionPayload;
use App\Support\Advertising\Results\DeliveryResult;

interface ReportsConversions
{
    /**
     * @param  array<int, ConversionPayload>  $payloads
     * @return list<DeliveryResult>
     */
    public function deliver(array $payloads): array;
}
