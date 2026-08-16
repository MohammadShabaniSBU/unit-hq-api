<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiAgent;
use Database\Seeders\AiAgentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiAgentSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeds_support_and_sales(): void
    {
        $this->seed(AiAgentSeeder::class);

        $this->assertSame(2, AiAgent::query()->count());
        $this->assertTrue(AiAgent::query()->where('key', 'support')->where('name', 'Support Agent')->exists());
        $this->assertTrue(AiAgent::query()->where('key', 'sales')->where('name', 'Sales Agent')->exists());
        $this->assertSame(
            config('agents.default_model'),
            AiAgent::query()->where('key', 'support')->value('model'),
        );
    }

    #[Test]
    public function rerun_is_idempotent(): void
    {
        $this->seed(AiAgentSeeder::class);
        $before = AiAgent::query()->orderBy('key')->get(['id', 'key', 'name', 'model'])->toArray();

        $this->seed(AiAgentSeeder::class);

        $this->assertSame($before, AiAgent::query()->orderBy('key')->get(['id', 'key', 'name', 'model'])->toArray());
        $this->assertSame(2, AiAgent::query()->count());
    }
}
