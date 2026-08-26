<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai;

use App\Models\AgentChannelBinding;
use App\Models\AiAgent;
use App\Models\Site;
use App\Support\Ai\AgentChannelBindings;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\BindingAudience;
use App\Support\Ai\Enums\BindingMode;
use App\Support\Ai\Enums\OutsideHoursPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentChannelBindingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function prefers_the_site_row_over_the_company_row(): void
    {
        $site = Site::factory()->create();
        $companyAgent = AiAgent::factory()->create(['key' => 'sales']);
        $siteAgent = AiAgent::factory()->create(['key' => 'support']);

        AgentChannelBinding::factory()->create([
            'ai_agent_id' => $companyAgent->id,
            'channel' => AgentChannel::Sms,
            'site_id' => null,
            'mode' => BindingMode::Auto,
        ]);

        $siteRow = AgentChannelBinding::factory()->create([
            'ai_agent_id' => $siteAgent->id,
            'channel' => AgentChannel::Sms,
            'site_id' => $site->id,
            'mode' => BindingMode::Draft,
            'audience' => BindingAudience::ExistingTenants,
            'outside_hours' => OutsideHoursPolicy::Answer,
        ]);

        $resolved = app(AgentChannelBindings::class)->resolve(AgentChannel::Sms, $site->id);

        $this->assertNotNull($resolved);
        $this->assertSame($siteRow->id, $resolved->binding->id);
        $this->assertSame($siteAgent->id, $resolved->agent->id);
        $this->assertSame(BindingMode::Draft, $resolved->mode);
        $this->assertSame(BindingAudience::ExistingTenants, $resolved->audience);
        $this->assertSame(OutsideHoursPolicy::Answer, $resolved->outsideHours);
    }

    #[Test]
    public function falls_back_to_the_company_row(): void
    {
        $site = Site::factory()->create();
        $agent = AiAgent::factory()->create(['key' => 'sales']);

        $company = AgentChannelBinding::factory()->create([
            'ai_agent_id' => $agent->id,
            'channel' => AgentChannel::Whatsapp,
            'site_id' => null,
            'mode' => BindingMode::Draft,
        ]);

        $resolved = app(AgentChannelBindings::class)->resolve(AgentChannel::Whatsapp, $site->id);

        $this->assertNotNull($resolved);
        $this->assertSame($company->id, $resolved->binding->id);
    }

    #[Test]
    public function returns_null_when_neither_site_nor_company_row_exists(): void
    {
        $site = Site::factory()->create();

        $this->assertNull(app(AgentChannelBindings::class)->resolve(AgentChannel::Email, $site->id));
        $this->assertNull(app(AgentChannelBindings::class)->resolve(AgentChannel::Email));
    }

    #[Test]
    public function ignores_archived_rows(): void
    {
        $site = Site::factory()->create();
        $agent = AiAgent::factory()->create();

        AgentChannelBinding::factory()->archived()->create([
            'ai_agent_id' => $agent->id,
            'channel' => AgentChannel::Sms,
            'site_id' => $site->id,
            'mode' => BindingMode::Auto,
        ]);

        $company = AgentChannelBinding::factory()->create([
            'ai_agent_id' => $agent->id,
            'channel' => AgentChannel::Sms,
            'site_id' => null,
            'mode' => BindingMode::Draft,
        ]);

        $resolved = app(AgentChannelBindings::class)->resolve(AgentChannel::Sms, $site->id);

        $this->assertNotNull($resolved);
        $this->assertSame($company->id, $resolved->binding->id);
    }

    #[Test]
    public function ignores_inactive_and_archived_agents(): void
    {
        $site = Site::factory()->create();
        $inactive = AiAgent::factory()->create(['is_active' => false]);
        $archived = AiAgent::factory()->archived()->create();
        $live = AiAgent::factory()->create();

        AgentChannelBinding::factory()->create([
            'ai_agent_id' => $inactive->id,
            'channel' => AgentChannel::Webchat,
            'site_id' => $site->id,
            'mode' => BindingMode::Auto,
        ]);

        AgentChannelBinding::factory()->create([
            'ai_agent_id' => $archived->id,
            'channel' => AgentChannel::Webchat,
            'site_id' => null,
            'mode' => BindingMode::Auto,
        ]);

        $this->assertNull(app(AgentChannelBindings::class)->resolve(AgentChannel::Webchat, $site->id));

        AgentChannelBinding::factory()->create([
            'ai_agent_id' => $live->id,
            'channel' => AgentChannel::Email,
            'site_id' => null,
            'mode' => BindingMode::Off,
        ]);

        $resolved = app(AgentChannelBindings::class)->resolve(AgentChannel::Email);
        $this->assertNotNull($resolved);
        $this->assertSame(BindingMode::Off, $resolved->mode);
        $this->assertSame($live->id, $resolved->agent->id);
    }
}
