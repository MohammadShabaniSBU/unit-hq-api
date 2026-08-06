<?php

declare(strict_types=1);

namespace App\Support\Insights\Providers;

use App\Models\InsightReport;
use App\Support\Communications\Results\VerificationResult;
use App\Support\Insights\Contracts\AnalyticsProvider;
use App\Support\Insights\Contracts\SignsEmbedTokens;
use App\Support\Insights\IframeHostGuard;
use Illuminate\Support\Facades\Http;
use LogicException;

final class IframeProvider implements AnalyticsProvider, SignsEmbedTokens
{
    private function __construct(
        private readonly string $baseUrl,
    ) {}

    public static function make(array $credentials, string $baseUrl): static
    {
        return new self($baseUrl);
    }

    public function credentialFields(): array
    {
        return [];
    }

    public function verify(): VerificationResult
    {
        try {
            IframeHostGuard::assertAllowed($this->baseUrl);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $messages = $e->errors();
            $first = $messages['base_url'][0] ?? 'iframe URL template is not allowed.';

            return VerificationResult::failed($first);
        }

        $probeUrl = IframeHostGuard::probeUrl($this->baseUrl);

        try {
            $response = Http::timeout(15)->head($probeUrl);
        } catch (\Throwable) {
            return VerificationResult::failed(
                'Host is unreachable (reachability check only — not authentication).'
            );
        }

        if ($response->successful() || $response->status() === 405) {
            return VerificationResult::ok();
        }

        return VerificationResult::failed(
            'Host returned HTTP '.$response->status().' (reachability check only — not authentication).'
        );
    }

    public function resourceKinds(): array
    {
        return [];
    }

    public function embedUrl(InsightReport $report, array $resolvedParams): string
    {
        throw new LogicException('Embed URL substitution lands in task 04.');
    }
}
