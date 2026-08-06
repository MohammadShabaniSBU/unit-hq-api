<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Insights;

use App\Enums\InsightReportSource;
use App\Enums\InsightResourceKind;
use App\Enums\InsightSiteScopeMode;
use App\Enums\InsightVisibility;
use App\Models\InsightReport;
use App\Support\Insights\Exceptions\EmbedUrlException;
use App\Support\Insights\Providers\IframeProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IframeProviderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function rejects_unfilled_placeholder(): void
    {
        config(['insights.iframe_host_allowlist' => ['charts.example.com']]);

        $provider = IframeProvider::make([], 'https://charts.example.com/embed/{resource}/{site_id}');
        $report = $this->makeReport();

        try {
            $provider->embedUrl($report, ['resource' => 'board-1']);
            $this->fail('Expected EmbedUrlException for unfilled placeholder.');
        } catch (EmbedUrlException $e) {
            $this->assertSame('param_unresolved', $e->reasonKey);
            $this->assertSame(422, $e->statusCode);
        }
    }

    #[Test]
    public function rejects_host_that_left_allowlist(): void
    {
        config(['insights.iframe_host_allowlist' => ['charts.example.com']]);

        $provider = IframeProvider::make([], 'https://{host}/embed/board');
        $report = $this->makeReport();

        $this->expectException(ValidationException::class);

        $provider->embedUrl($report, [
            'host' => 'evil.example.com',
        ]);
    }

    private function makeReport(): InsightReport
    {
        return new InsightReport([
            'key' => 'iframe-test',
            'source' => InsightReportSource::Embedded,
            'resource_kind' => InsightResourceKind::Dashboard,
            'resource_ref' => '1',
            'visibility' => InsightVisibility::All,
            'site_scope_mode' => InsightSiteScopeMode::Inherit,
            'options' => [],
            'is_system' => false,
        ]);
    }
}
