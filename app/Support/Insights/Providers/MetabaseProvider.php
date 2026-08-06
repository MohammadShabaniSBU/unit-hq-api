<?php

declare(strict_types=1);

namespace App\Support\Insights\Providers;

use App\Enums\InsightParamBinding;
use App\Models\InsightReport;
use App\Support\Communications\Results\VerificationResult;
use App\Support\Insights\Contracts\AnalyticsProvider;
use App\Support\Insights\Contracts\DescribesResourceParams;
use App\Support\Insights\Contracts\ListsResources;
use App\Support\Insights\Contracts\SignsEmbedTokens;
use App\Support\Insights\Exceptions\EmbedUrlException;
use App\Support\Insights\Hs256Jwt;
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
        $secret = $this->credentials['embedding_secret_key'] ?? null;

        if (! is_string($secret) || $secret === '') {
            throw EmbedUrlException::credentialsUnreadable();
        }

        if ($this->baseUrl === '') {
            throw EmbedUrlException::credentialsUnreadable();
        }

        $kind = $report->resource_kind?->value;
        $ref = $report->resource_ref;

        if ($kind === null || $ref === null || $ref === '') {
            throw new EmbedUrlException('provider_not_embeddable', 409);
        }

        [$locked, $defaults] = $this->splitByBinding($report, $resolvedParams);

        $ttl = (int) config('insights.embed_ttl_minutes', 10);
        $exp = now()->addMinutes($ttl)->getTimestamp();

        $payload = [
            'resource' => [$kind => (int) $ref],
            'params' => $locked,
            'exp' => $exp,
        ];

        $token = Hs256Jwt::encode($payload, $secret);

        $url = $this->baseUrl.'/embed/'.$kind.'/'.$token;

        $query = $this->editableQuery($defaults);
        if ($query !== '') {
            $url .= '?'.$query;
        }

        $options = is_array($report->options) ? $report->options : [];
        if ($options !== []) {
            $url .= '#'.$this->hashQuery($options);
        }

        return $url;
    }

    public function resources(string $kind): array
    {
        throw new LogicException('Resource listing lands in task 05.');
    }

    public function resourceParams(string $kind, string $ref): array
    {
        throw new LogicException('Resource param discovery lands in task 05.');
    }

    /**
     * @param  array<string, mixed>  $resolvedParams
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function splitByBinding(InsightReport $report, array $resolvedParams): array
    {
        $locked = [];
        $defaults = [];

        $bindings = [];
        foreach ($report->params as $param) {
            $bindings[$param->name] = $param->binding;
        }

        foreach ($resolvedParams as $name => $value) {
            $binding = $bindings[$name] ?? InsightParamBinding::Locked;

            if ($binding === InsightParamBinding::Default) {
                $defaults[$name] = $value;
            } else {
                $locked[$name] = $value;
            }
        }

        return [$locked, $defaults];
    }

    /**
     * @param  array<string, mixed>  $defaults
     */
    private function editableQuery(array $defaults): string
    {
        if ($defaults === []) {
            return '';
        }

        return http_build_query($defaults);
    }

    /**
     * Metabase appearance flags expect `true`/`false` literals, not `1`/`0`.
     *
     * @param  array<string, mixed>  $options
     */
    private function hashQuery(array $options): string
    {
        $normalized = [];

        foreach ($options as $key => $value) {
            $normalized[$key] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                default => $value,
            };
        }

        return http_build_query($normalized);
    }
}
