<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Models\AiProviderAccount;
use App\Support\Ai\AiProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiProviderRegistryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function returns_null_for_provider_and_model_when_nothing_is_configured(): void
    {
        $registry = new AiProviderRegistry;

        $this->assertNull($registry->applyActiveCredentials());
        $this->assertNull($registry->activeModel());
    }

    #[Test]
    public function resolves_the_default_accounts_provider_and_model(): void
    {
        AiProviderAccount::query()->create([
            'provider' => 'anthropic',
            'display_name' => 'Main',
            'credentials' => ['api_key' => 'sk-ant-configured-key'],
            'allowed_models' => ['claude-sonnet-5', 'claude-opus-5'],
            'default_model' => 'claude-opus-5',
            'connection_status' => 'connected',
            'is_default' => true,
        ]);

        $registry = new AiProviderRegistry;

        $this->assertSame('anthropic', $registry->applyActiveCredentials());
        $this->assertSame('sk-ant-configured-key', config('ai.providers.anthropic.key'));
        $this->assertSame('claude-opus-5', $registry->activeModel());
    }

    #[Test]
    public function ignores_archived_accounts_even_if_flagged_default(): void
    {
        AiProviderAccount::query()->create([
            'provider' => 'anthropic',
            'display_name' => 'Archived',
            'credentials' => ['api_key' => 'sk-ant-stale'],
            'allowed_models' => ['claude-sonnet-5'],
            'default_model' => 'claude-sonnet-5',
            'connection_status' => 'connected',
            'is_default' => true,
            'archived_at' => now(),
        ]);

        $registry = new AiProviderRegistry;

        $this->assertNull($registry->applyActiveCredentials());
        $this->assertNull($registry->activeModel());
    }
}
