<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Tools;

use App\Models\Site;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\ToolErrorCode;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Tools\CalendarResolveTool;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalendarResolveToolTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function next_monday_on_wednesday_licenses_iso_and_slash_forms(): void
    {
        Carbon::setTestNow('2026-08-26 12:00:00');
        $principal = AgentPrincipal::anonymous(null, 'en');
        $result = (new CalendarResolveTool)->handle($principal, ['phrase' => 'next Monday']);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame('2026-08-31', $result->data['iso']);
        $this->assertSame('Monday', $result->data['weekday']);
        $this->assertSame('next Monday', $result->data['phrase']);
        $this->assertSame('UTC', $result->data['timezone']);
        $this->assertSame('"next Monday" → 2026-08-31 (Monday)', $result->display);
        $this->assertTrue($result->facts->contains('2026-08-31'));
        $this->assertTrue($result->facts->contains('31/08/2026'));
        $this->assertSame([], $result->entities);
        $this->assertStringNotContainsString('Refs:', $result->modelText());
    }

    #[Test]
    public function site_timezone_defines_today(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-08-26 02:00:00', 'UTC'));
        $site = Site::factory()->create(['timezone' => 'America/Los_Angeles']);
        $principal = AgentPrincipal::anonymous($site->id, 'en');
        $result = (new CalendarResolveTool)->handle($principal, [
            'phrase' => 'today',
            'site_id' => $site->id,
        ]);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame('2026-08-25', $result->data['iso']);
        $this->assertSame('America/Los_Angeles', $result->data['timezone']);
    }

    #[Test]
    public function app_timezone_when_no_site(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-08-26 02:00:00', 'UTC'));
        $principal = AgentPrincipal::anonymous(null, 'en');
        $result = (new CalendarResolveTool)->handle($principal, ['phrase' => 'today']);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame('2026-08-26', $result->data['iso']);
        $this->assertSame('UTC', $result->data['timezone']);
    }

    #[Test]
    public function garbage_phrase_is_invalid_arguments_with_the_hint(): void
    {
        Carbon::setTestNow('2026-08-26 12:00:00');
        $result = (new CalendarResolveTool)->handle(
            AgentPrincipal::anonymous(null, 'en'),
            ['phrase' => 'sometime soon-ish'],
        );

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(ToolErrorCode::InvalidArguments, $result->error?->errorCode);
        $this->assertSame('ask the customer for the exact date', $result->error?->recovery['hint'] ?? null);
        $this->assertStringContainsString('ask the customer for the exact date', $result->display);
    }

    #[Test]
    public function over_length_phrase_is_invalid_arguments(): void
    {
        $result = (new CalendarResolveTool)->handle(
            AgentPrincipal::anonymous(null, 'en'),
            ['phrase' => str_repeat('a', 61)],
        );

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(ToolErrorCode::InvalidArguments, $result->error?->errorCode);
        $this->assertSame('ask the customer for the exact date', $result->error?->recovery['hint'] ?? null);
    }

    #[Test]
    public function weekday_in_display_follows_the_principal_locale(): void
    {
        Carbon::setTestNow('2026-08-26 12:00:00');
        $monday = CarbonImmutable::parse('2026-08-31');
        $result = (new CalendarResolveTool)->handle(
            AgentPrincipal::anonymous(null, 'es'),
            ['phrase' => 'próximo lunes'],
        );

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertStringContainsString($monday->locale('es')->isoFormat('dddd'), $result->display);
        $this->assertSame('Monday', $result->data['weekday']);
    }
}
