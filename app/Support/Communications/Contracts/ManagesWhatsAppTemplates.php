<?php

declare(strict_types=1);

namespace App\Support\Communications\Contracts;

use App\Support\Communications\Messages\WhatsAppTemplateDraft;
use App\Support\Communications\Results\ProviderTemplateRef;
use App\Support\Communications\Results\TemplateStatusSnapshot;

interface ManagesWhatsAppTemplates
{
    public function submit(WhatsAppTemplateDraft $draft): ProviderTemplateRef;

    public function fetchStatus(string $providerTemplateId): TemplateStatusSnapshot;

    /**
     * @return list<TemplateStatusSnapshot>
     */
    public function listNonTerminalStatuses(): array;

    /**
     * Latency path: parse template status events from a webhook payload.
     * Returns [] when the payload is not a template-status event.
     *
     * @param  array<string, mixed>  $payload
     * @return list<TemplateStatusSnapshot>
     */
    public function parseTemplateStatusEvents(array $payload): array;
}
