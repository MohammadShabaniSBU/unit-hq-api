<?php

declare(strict_types=1);

namespace App\Support\Communications\Contracts;

use App\Support\Communications\Results\DeliveryEvent;

interface ReportsDeliveryEvents
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<DeliveryEvent>
     */
    public function parseDeliveryEvents(array $payload): array;
}
