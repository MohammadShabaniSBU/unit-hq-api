<?php

declare(strict_types=1);

namespace App\Support\Insights\Providers;

use App\Models\InsightReport;
use App\Support\Communications\Results\VerificationResult;
use App\Support\Insights\Contracts\AnalyticsProvider;
use App\Support\Insights\Contracts\DescribesResourceParams;
use App\Support\Insights\Contracts\ListsResources;
use App\Support\Insights\Contracts\SignsEmbedTokens;
use Illuminate\Support\Facades\Http;
use LogicException;

final class MetabaseProvider implements AnalyticsProvider, SignsEmbedTokens, ListsResources, DescribesResourceParams
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    private function __construct(
        private readonly array $credentials,
        private readonly string $baseUrl,
    ) {}

    public static function make(array $credentials, string $baseUrl): static
    {
        return new self($credentials, rtrim($baseUrl, '/'));
    }

    public function credentialFields(): array
    {
        return [
            'embedding_secret_key' => ['label' => 'Embedding secret key', 'secret' => true],
            'api_key' => ['label' => 'API key', 'secret' => true],
        ];
    }

    public function verify(): VerificationResult
    {
        $apiKey = $this->credentials['api_key'] ?? null;

        if (! is_string($apiKey) || $apiKey === '') {
            return VerificationResult::failed('Metabase API key is required.');
        }

        if ($this->baseUrl === '') {
            return VerificationResult::failed('Metabase base URL is required.');
        }

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $apiKey,
                'Accept' => 'application/json',
            ])
                ->timeout(15)
                ->get($this->baseUrl.'/api/user/current');
        } catch (\Throwable) {
            return VerificationResult::failed('Could not reach the Metabase instance.');
        }

        if ($response->successful()) {
            return VerificationResult::ok();
        }

        return VerificationResult::failed(
            'Metabase rejected the API key ('.$response->status().').'
        );
    }

    public function resourceKinds(): array
    {
        return ['dashboard', 'question'];
    }

    public function embedUrl(InsightReport $report, array $resolvedParams): string
    {
        throw new LogicException('Embed token minting lands in task 04.');
    }

    public function resources(string $kind): array
    {
        throw new LogicException('Resource listing lands in task 05.');
    }

    public function resourceParams(string $kind, string $ref): array
    {
        throw new LogicException('Resource param discovery lands in task 05.');
    }
}
