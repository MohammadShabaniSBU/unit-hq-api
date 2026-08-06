<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Insights;

use App\Models\Employee;
use App\Models\Site;
use App\Support\Auth\Permission;
use App\Support\Insights\DynamicParamContext;
use App\Support\Insights\DynamicParams;
use App\Support\Insights\Exceptions\UnknownDynamicParamKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DynamicParamsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function unknown_key_fails_closed(): void
    {
        $employee = Employee::factory()->manager()->create();
        $ctx = new DynamicParamContext($employee, null, null, 'en', true);

        $this->expectException(UnknownDynamicParamKey::class);

        DynamicParams::resolve('not_a_whitelisted_key', $ctx);
    }

    #[Test]
    public function today_uses_site_timezone(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-06 22:30:00', 'UTC'));

        $employee = Employee::factory()->manager()->create();
        $site = Site::factory()->create([
            'timezone' => 'Pacific/Kiritimati', // UTC+14 — already 2026-08-07
        ]);

        $ctx = new DynamicParamContext($employee, $site->id, $site, 'en', true);

        $this->assertSame('2026-08-07', DynamicParams::resolve('today', $ctx));

        CarbonImmutable::setTestNow();
    }

    #[Test]
    public function visible_site_ids_matches_site_access(): void
    {
        $employee = Employee::factory()->manager()->create();
        $sites = Site::factory()->count(3)->create();
        $expected = $employee->siteIdsFor(Permission::ReportView);

        $ctx = new DynamicParamContext($employee, null, null, 'en', true);
        $resolved = DynamicParams::resolve('visible_site_ids', $ctx);

        $this->assertIsArray($resolved);

        if ($expected === null) {
            $this->assertSame(
                $sites->pluck('id')->sort()->values()->all(),
                collect($resolved)->sort()->values()->all()
            );
        } else {
            $this->assertSame(
                array_values($expected),
                array_values($resolved)
            );
        }
    }
}
