<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentConversation;
use App\Models\AiAgent;
use App\Models\Site;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Agents\SalesAgentDefinition;
use App\Support\Ai\ChannelProfile;
use App\Support\Ai\Enums\AgentChannel;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PromptVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function prompt_version_ignores_site_identity_and_civil_date(): void
    {
        $definition = new SalesAgentDefinition;
        $agent = AiAgent::factory()->create(['key' => 'sales', 'is_active' => true]);
        $madrid = Site::factory()->create([
            'name' => 'Madrid Norte',
            'timezone' => 'Europe/Madrid',
        ]);
        $barcelona = Site::factory()->create([
            'name' => 'Barcelona Port',
            'timezone' => 'Europe/Madrid',
        ]);

        $madridCtx = $this->context($definition, $agent, $madrid, AgentChannel::Sms);
        $barcelonaCtx = $this->context($definition, $agent, $barcelona, AgentChannel::Sms);
        $emailCtx = $this->context($definition, $agent, $madrid, AgentChannel::Email);

        Carbon::setTestNow('2026-09-01 12:00:00');
        $madridVersion = $definition->promptVersion($madridCtx);
        $fullMadrid = $definition->systemPrompt($madridCtx);

        Carbon::setTestNow('2026-09-02 12:00:00');
        $nextDayVersion = $definition->promptVersion($madridCtx);
        $fullNextDay = $definition->systemPrompt($madridCtx);

        $this->assertSame($madridVersion, $nextDayVersion);
        $this->assertSame($madridVersion, $definition->promptVersion($barcelonaCtx));
        $this->assertNotSame($fullMadrid, $fullNextDay);
        $this->assertNotSame($fullMadrid, $definition->systemPrompt($barcelonaCtx));
        $this->assertNotSame($madridVersion, $definition->promptVersion($emailCtx));
    }

    private function context(
        SalesAgentDefinition $definition,
        AiAgent $agent,
        Site $site,
        AgentChannel $channel,
    ): AgentContext {
        $conversation = AgentConversation::factory()->anonymous()->create([
            'ai_agent_id' => $agent->id,
            'site_id' => $site->id,
            'channel' => $channel,
        ]);

        return new AgentContext(
            AgentPrincipal::anonymous($site->id, 'en'),
            ChannelProfile::for($channel),
            $definition,
            $conversation,
            $agent,
        );
    }
}
