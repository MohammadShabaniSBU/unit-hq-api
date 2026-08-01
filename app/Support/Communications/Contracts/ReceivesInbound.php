<?php

declare(strict_types=1);

namespace App\Support\Communications\Contracts;

use App\Support\Communications\Results\InboundMessage;

interface ReceivesInbound
{
    /**
     * Parse an inbound content webhook. Returns null when the payload is not
     * inbound content (e.g. a delivery-status callback on a shared URL).
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseInbound(array $payload): ?InboundMessage;
}
