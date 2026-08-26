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

        AiAgent::query()->updateOrCreate(
            ['key' => 'support'],
            [
                'name' => 'Support Agent',
                'description' => null,
                'model' => $model,
                'is_active' => true,
            ],
        );

        $sales = AiAgent::query()->updateOrCreate(
            ['key' => 'sales'],
            [
                'name' => 'Sales Agent',
                'description' => null,
                'model' => $model,
                'is_active' => true,
            ],
        );

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

        $this->call(AgentChannelBindingSeeder::class);
    }
}
