<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Tools;

use App\Ai\Tools\ResolveCalendar;
use App\Models\Employee;
use App\Models\Site;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function next_monday_returns_iso_date(): void
    {
        Carbon::setTestNow('2026-08-26 12:00:00');
        $employee = Employee::factory()->withoutRoleGrant()->create();

        $result = json_decode((new ResolveCalendar($employee))->handle(new Request([
            'phrase' => 'next Monday',
        ])), true);

        $this->assertTrue($result['success']);
        $this->assertSame('2026-08-31', $result['iso']);
        $this->assertSame('Monday', $result['weekday']);
        $this->assertSame('next Monday', $result['phrase']);
        $this->assertSame('UTC', $result['timezone']);
        $this->assertSame('"next Monday" → 2026-08-31 (Monday)', $result['display']);
    }

    #[Test]
    public function garbage_phrase_fails(): void
    {
        Carbon::setTestNow('2026-08-26 12:00:00');
        $employee = Employee::factory()->withoutRoleGrant()->create();

        $result = json_decode((new ResolveCalendar($employee))->handle(new Request([
            'phrase' => 'sometime soon-ish',
        ])), true);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ask the customer for the exact date', $result['error']);
    }

    #[Test]
    public function site_id_timezone_defines_today(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-08-26 02:00:00', 'UTC'));
        $employee = Employee::factory()->withoutRoleGrant()->create();
        $site = Site::factory()->create(['timezone' => 'America/Los_Angeles']);

        $result = json_decode((new ResolveCalendar($employee))->handle(new Request([
            'phrase' => 'today',
            'site_id' => $site->id,
        ])), true);

        $this->assertTrue($result['success']);
        $this->assertSame('2026-08-25', $result['iso']);
        $this->assertSame('America/Los_Angeles', $result['timezone']);
    }
}
