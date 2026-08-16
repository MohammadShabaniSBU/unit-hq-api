<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AiAgent;
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

        AiAgent::query()->updateOrCreate(
            ['key' => 'sales'],
            [
                'name' => 'Sales Agent',
                'description' => null,
                'model' => $model,
                'is_active' => true,
            ],
        );
    }
}
