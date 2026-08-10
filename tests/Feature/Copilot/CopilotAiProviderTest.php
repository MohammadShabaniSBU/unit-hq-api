<?php

declare(strict_types=1);

namespace Tests\Feature\Copilot;

use App\Ai\Agents\CrmCopilotAgent;
use App\Models\AiProviderAccount;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CopilotAiProviderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function falls_back_to_sdk_defaults_when_no_account_is_configured(): void
    {
        $agent = new CrmCopilotAgent(Employee::factory()->create());

        $this->assertNull($agent->provider());
        $this->assertNull($agent->model());
    }

    #[Test]
    public function uses_the_configured_default_accounts_provider_and_model(): void
    {
        AiProviderAccount::query()->create([
            'provider' => 'anthropic',
            'display_name' => 'Main',
            'credentials' => ['api_key' => 'sk-ant-configured-key'],
            'allowed_models' => ['claude-sonnet-5', 'claude-haiku-4-5-20251001'],
            'default_model' => 'claude-haiku-4-5-20251001',
            'connection_status' => 'connected',
            'is_default' => true,
        ]);

        $agent = new CrmCopilotAgent(Employee::factory()->create());

        $this->assertSame('anthropic', $agent->provider());
        $this->assertSame('claude-haiku-4-5-20251001', $agent->model());
    }
}
