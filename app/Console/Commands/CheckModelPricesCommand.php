<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Ai\ModelPriceCheck;
use Illuminate\Console\Command;

class CheckModelPricesCommand extends Command
{
    protected $signature = 'agents:check-model-prices';

    protected $description = 'Fail when a recently used or configured model has no effective ai_model_prices row';

    public function handle(): int
    {
        $missing = ModelPriceCheck::missing();

        if ($missing === []) {
            $this->info('agents:check-model-prices — catalogue covers used and configured models.');

            return self::SUCCESS;
        }

        $this->error('agents:check-model-prices — missing effective price row(s):');
        foreach ($missing as $row) {
            $provider = $row['provider'] ?? '(null)';
            $this->line("  {$provider} / {$row['model']} ({$row['source']})");
        }

        return self::FAILURE;
    }
}
