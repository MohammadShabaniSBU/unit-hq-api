<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Ai\VoiceBridgeCustomerConfig;
use Illuminate\Console\Command;

class ExportVoiceBridgeConfigCommand extends Command
{
    protected $signature = 'agents:export-voice-bridge-config';

    protected $description = 'Write vb-customer-config.json from ai-handoff.voice_greeting (unsubstituted {company} templates)';

    public function handle(): int
    {
        $path = VoiceBridgeCustomerConfig::path();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            $this->error("Directory missing: {$dir}");

            return self::FAILURE;
        }

        file_put_contents($path, VoiceBridgeCustomerConfig::encoded());
        $this->info('Wrote '.VoiceBridgeCustomerConfig::RELATIVE_PATH);

        return self::SUCCESS;
    }
}
