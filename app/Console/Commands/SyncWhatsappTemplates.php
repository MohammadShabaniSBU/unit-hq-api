<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Communications\WhatsAppTemplateSync;
use Illuminate\Console\Command;

/**
 * Authoritative poll of WhatsApp template approval state from the provider.
 * Webhooks (when offered) are latency; a missed event must not strand status.
 */
class SyncWhatsappTemplates extends Command
{
    protected $signature = 'whatsapp:sync-templates';

    protected $description = 'Poll provider for WhatsApp template approval / rejection / revocation';

    public function handle(WhatsAppTemplateSync $sync): int
    {
        $updated = $sync->pollAll();
        $this->info("Updated {$updated} WhatsApp template row(s).");

        return self::SUCCESS;
    }
}
