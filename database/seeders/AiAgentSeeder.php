<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AiAgent;
use App\Support\Ai\Enums\WritePolicyMode;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AiAgentSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $model = (string) config('agents.default_model');

        $support = AiAgent::query()->updateOrCreate(
            ['key' => 'support'],
            [
                'name' => 'Support Agent (archived)',
                'description' => null,
                'model' => $model,
                'is_active' => false,
            ],
        );
        $this->archiveOnce($support);

        $sales = AiAgent::query()->updateOrCreate(
            ['key' => 'sales'],
            [
                'name' => 'Sales Agent (archived)',
                'description' => null,
                'model' => $model,
                'is_active' => false,
            ],
        );
        $this->archiveOnce($sales);

        $sales->writePolicies()->updateOrCreate(
            ['tool_key' => 'sales.create_offer'],
            [
                'mode' => WritePolicyMode::Commit,
                'max_per_conversation' => 2,
                'max_per_day' => 50,
            ],
        );

        $sales->writePolicies()->updateOrCreate(
            ['tool_key' => 'sales.create_reservation'],
            [
                'mode' => WritePolicyMode::Propose,
                'max_per_conversation' => 1,
                'max_per_day' => 20,
            ],
        );

        $concierge = AiAgent::query()->updateOrCreate(
            ['key' => 'concierge'],
            [
                'name' => 'Customer Agent',
                'description' => null,
                'model' => $model,
                'is_active' => true,
            ],
        );

        // Carried forward from sales, unchanged. sales.create_reservation at
        // propose / 1 / 20 is the reason a reservation needs a click. S27-02's
        // binding repoint leaves existing agent_pending_actions on the legacy
        // agent on the assumption these caps survive the merge — do not widen.
        $concierge->writePolicies()->updateOrCreate(
            ['tool_key' => 'sales.create_offer'],
            [
                'mode' => WritePolicyMode::Commit,
                'max_per_conversation' => 2,
                'max_per_day' => 50,
            ],
        );
        $concierge->writePolicies()->updateOrCreate(
            ['tool_key' => 'sales.create_reservation'],
            [
                'mode' => WritePolicyMode::Propose,
                'max_per_conversation' => 1,
                'max_per_day' => 20,
            ],
        );
        $concierge->writePolicies()->updateOrCreate(
            ['tool_key' => 'identity.request_code'],
            [
                'mode' => WritePolicyMode::Commit,
                'max_per_conversation' => 3,
                'max_per_day' => 10,
            ],
        );
        $concierge->writePolicies()->updateOrCreate(
            ['tool_key' => 'voice.send_quote_by_text'],
            [
                'mode' => WritePolicyMode::Commit,
                'max_per_conversation' => 3,
                'max_per_day' => 10,
            ],
        );

        $this->call(AgentChannelBindingSeeder::class);
    }

    private function archiveOnce(AiAgent $agent): void
    {
        if ($agent->archived_at === null) {
            $agent->forceFill(['archived_at' => now()])->save();
        }
    }
}
