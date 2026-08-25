<?php

declare(strict_types=1);

use App\Models\AiModelPrice;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $exists = AiModelPrice::query()
            ->where('provider', 'anthropic')
            ->where('model', 'claude-sonnet-4-6')
            ->exists();

        if ($exists) {
            return;
        }

        AiModelPrice::publish('anthropic', 'claude-sonnet-4-6', [
            'input_per_mtok' => '3.0000',
            'cached_input_per_mtok' => '0.3000',
            'output_per_mtok' => '15.0000',
            'currency' => 'USD',
            'effective_from' => '2026-01-01',
        ]);
    }

    public function down(): void
    {
        AiModelPrice::query()
            ->where('provider', 'anthropic')
            ->where('model', 'claude-sonnet-4-6')
            ->where('effective_from', '2026-01-01')
            ->delete();
    }
};
