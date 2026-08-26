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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IdentityBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function today_is_emitted_without_a_site_in_the_app_timezone(): void
    {
        Carbon::setTestNow('2026-08-26 12:00:00');
        $prompt = $this->prompt(null, 'en');
        $weekday = CarbonImmutable::parse('2026-08-26')->locale('en')->isoFormat('dddd');

        $this->assertStringContainsString("Today: 2026-08-26 ({$weekday})", $prompt);
        $this->assertStringNotContainsString('Today at this site', $prompt);
        $this->assertStringNotContainsString('Site:', $prompt);
    }

    #[Test]
    public function today_uses_the_site_timezone_when_a_site_is_set(): void
    {
        // 2026-08-26 02:00 UTC is still 2026-08-25 in Los Angeles.
        Carbon::setTestNow(CarbonImmutable::parse('2026-08-26 02:00:00', 'UTC'));
        $site = Site::factory()->create([
            'name' => 'LA Depot',
            'timezone' => 'America/Los_Angeles',
        ]);

        $prompt = $this->prompt($site, 'en');
        $weekday = CarbonImmutable::parse('2026-08-25')->locale('en')->isoFormat('dddd');

        $this->assertStringContainsString("Today: 2026-08-25 ({$weekday})", $prompt);
        $this->assertStringContainsString('Site: LA Depot.', $prompt);
        $this->assertStringContainsString('Civil timezone: America/Los_Angeles.', $prompt);
        $this->assertStringNotContainsString('Today at this site', $prompt);
    }

    #[Test]
    public function weekday_is_localised_to_the_principal_locale(): void
    {
        Carbon::setTestNow('2026-08-26 12:00:00');
        $wednesday = CarbonImmutable::parse('2026-08-26');

        $en = $this->prompt(null, 'en');
        $es = $this->prompt(null, 'es');
        $fr = $this->prompt(null, 'fr');

        $this->assertStringContainsString('Today: 2026-08-26 ('.$wednesday->locale('en')->isoFormat('dddd').')', $en);
        $this->assertStringContainsString('Today: 2026-08-26 ('.$wednesday->locale('es')->isoFormat('dddd').')', $es);
        $this->assertStringContainsString('Today: 2026-08-26 ('.$wednesday->locale('fr')->isoFormat('dddd').')', $fr);
        $this->assertNotSame($en, $es);
        $this->assertNotSame($en, $fr);
    }

    #[Test]
    public function prompt_version_ignores_the_today_line(): void
    {
        $definition = new SalesAgentDefinition;
        $agent = AiAgent::factory()->create(['key' => 'sales-'.uniqid(), 'is_active' => true]);
        $conversation = AgentConversation::factory()->anonymous()->create([
            'ai_agent_id' => $agent->id,
            'channel' => AgentChannel::Webchat,
        ]);
        $ctx = new AgentContext(
            AgentPrincipal::anonymous(null, 'en'),
            ChannelProfile::for(AgentChannel::Webchat),
            $definition,
            $conversation,
            $agent,
        );

        Carbon::setTestNow('2026-08-26 12:00:00');
        $first = $definition->promptVersion($ctx);
        $fullFirst = $definition->systemPrompt($ctx);

        Carbon::setTestNow('2026-08-27 12:00:00');
        $second = $definition->promptVersion($ctx);
        $fullSecond = $definition->systemPrompt($ctx);

        $this->assertSame($first, $second);
        $this->assertNotSame($fullFirst, $fullSecond);
    }

    private function prompt(?Site $site, string $locale): string
    {
        $definition = new SalesAgentDefinition;
        $agent = AiAgent::factory()->create(['key' => 'sales-'.uniqid(), 'is_active' => true]);
        $conversation = AgentConversation::factory()->anonymous()->create([
            'ai_agent_id' => $agent->id,
            'site_id' => $site?->id,
            'channel' => AgentChannel::Webchat,
            'locale' => $locale,
        ]);

        return $definition->systemPrompt(new AgentContext(
            AgentPrincipal::anonymous($site?->id, $locale),
            ChannelProfile::for(AgentChannel::Webchat),
            $definition,
            $conversation,
            $agent,
        ));
    }
}
