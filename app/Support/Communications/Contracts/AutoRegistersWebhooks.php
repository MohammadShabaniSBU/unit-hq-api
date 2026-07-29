<?php

declare(strict_types=1);

namespace App\Support\Communications\Contracts;

use App\Support\Communications\Results\WebhookRegistration;

interface AutoRegistersWebhooks
{
    /**
     * @param  list<string>  $events
     */
    public function createWebhook(string $url, array $events): WebhookRegistration;

    public function deleteWebhook(string $endpointId): void;

    /** @return list<string> */
    public function defaultWebhookEvents(): array;
}
