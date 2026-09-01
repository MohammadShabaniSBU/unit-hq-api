<?php

declare(strict_types=1);

use App\Models\AgentChannelBinding;
use App\Models\AiAgent;
use App\Support\RecordsActivity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $live = AgentChannelBinding::query()->live()->with('agent')->get();
            if ($live->isEmpty()) {
                return;
            }

            $concierge = AiAgent::query()->where('key', 'concierge')->first();
            if ($concierge === null) {
                throw new RuntimeException(
                    'Cannot repoint agent_channel_bindings: the concierge ai_agents row is missing. S27-03 must run first.',
                );
            }

            $duplicates = $live
                ->groupBy(static fn (AgentChannelBinding $row): string => $row->channel->value.'|'.($row->site_id ?? 0))
                ->filter(static fn ($group): bool => $group->count() > 1);
            if ($duplicates->isNotEmpty()) {
                throw new RuntimeException(
                    'Cannot repoint agent_channel_bindings: duplicate live (channel, site) pair. The partial unique index agent_channel_bindings_channel_site_idx is missing or was dropped.',
                );
            }

            foreach ($live as $binding) {
                if ($binding->ai_agent_id === $concierge->id) {
                    continue;
                }

                $fromAgentKey = $binding->agent->key;
                $binding->ai_agent_id = $concierge->id;
                $binding->updated_by_employee_id = null;
                $binding->save();

                RecordsActivity::core('ai.binding.updated', $binding, [
                    'channel' => $binding->channel->value,
                    'site_id' => $binding->site_id,
                    'from_agent_key' => $fromAgentKey,
                    'to_agent_key' => 'concierge',
                ], anonymous: true);
            }
        });
    }

    public function down(): void
    {
        // Original ai_agent_id is recoverable only from the activity log.
        // A data-merge rollback is not something down() should attempt.
    }
};
