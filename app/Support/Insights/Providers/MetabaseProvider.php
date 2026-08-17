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
use App\Support\Insights\Exceptions\DiscoveryException;
use App\Support\Insights\Exceptions\EmbedUrlException;
use App\Support\Insights\Hs256Jwt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final class MetabaseProvider implements AnalyticsProvider, SignsEmbedTokens, ListsResources, DescribesResourceParams
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    private function __construct(
        private readonly array $credentials,
        private readonly string $baseUrl,
        private readonly string $privateBaseUrl,
    ) {}

    public static function make(array $credentials, string $baseUrl, ?string $privateBaseUrl = null): static
    {
        $public = rtrim($baseUrl, '/');
        $private = is_string($privateBaseUrl) && $privateBaseUrl !== ''
            ? rtrim($privateBaseUrl, '/')
            : $public;

        return new self($credentials, $public, $private);
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

        if ($this->privateBaseUrl === '') {
            return VerificationResult::failed('Metabase private base URL is required.');
        }

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $apiKey,
                'Accept' => 'application/json',
            ])
                ->timeout(15)
                ->get($this->privateBaseUrl.'/api/user/current');
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
            'params' => (object) $locked,
            'exp' => $exp,
        ];

        $token = Hs256Jwt::encode($payload, $secret);

        $url = $this->baseUrl.'/embed/'.$kind.'/'.$token;

        $query = $this->editableQuery($defaults);
        if ($query !== '') {
            $url .= '?'.$query;
        }

        $options = is_array($report->options) ? $report->options : [];
        $hash = $this->hashQuery($options);
        if ($hash !== '') {
            $url .= '#'.$hash;
        }

        return $url;
    }

    public function resources(string $kind): array
    {
        $this->assertKnownKind($kind);

        $path = $kind === 'dashboard' ? '/api/dashboard' : '/api/card';
        $payload = $this->getJson($path);

        if (! is_array($payload)) {
            throw DiscoveryException::unreachable();
        }

        $items = array_is_list($payload) ? $payload : [];
        $out = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['id'])) {
                continue;
            }

            $collection = null;
            if (isset($item['collection']) && is_array($item['collection'])) {
                $collection = isset($item['collection']['name'])
                    ? (string) $item['collection']['name']
                    : null;
            }

            $out[] = [
                'ref' => (string) $item['id'],
                'name' => (string) ($item['name'] ?? ''),
                'collection' => $collection,
                'enabled_for_embedding' => (bool) ($item['enable_embedding'] ?? false),
            ];
        }

        return $out;
    }

    public function resourceParams(string $kind, string $ref): array
    {
        $this->assertKnownKind($kind);

        if ($ref === '' || ! ctype_digit($ref)) {
            throw DiscoveryException::unreachable();
        }

        $path = $kind === 'dashboard'
            ? '/api/dashboard/'.$ref
            : '/api/card/'.$ref;

        $payload = $this->getJson($path);

        if (! is_array($payload)) {
            throw DiscoveryException::unreachable();
        }

        $parameters = $payload['parameters'] ?? [];
        if (! is_array($parameters)) {
            $parameters = [];
        }

        $embeddingParams = $payload['embedding_params'] ?? [];
        if (! is_array($embeddingParams)) {
            $embeddingParams = [];
        }

        $out = [];

        foreach ($parameters as $param) {
            if (! is_array($param)) {
                continue;
            }

            $slug = isset($param['slug']) ? (string) $param['slug'] : '';
            if ($slug === '') {
                continue;
            }

            $mode = $embeddingParams[$slug] ?? 'disabled';
            $mode = is_string($mode) ? $mode : 'disabled';
            if (! in_array($mode, ['disabled', 'enabled', 'locked'], true)) {
                $mode = 'disabled';
            }

            $out[] = [
                'slug' => $slug,
                'name' => (string) ($param['name'] ?? $slug),
                'type' => (string) ($param['type'] ?? 'string'),
                'embedding_mode' => $mode,
                'required' => (bool) ($param['required'] ?? false),
            ];
        }

        return $out;
    }

    private function assertKnownKind(string $kind): void
    {
        if (! in_array($kind, $this->resourceKinds(), true)) {
            throw new InvalidArgumentException('Unknown Metabase resource kind: '.$kind);
        }
    }

    /**
     * @return array<mixed>|null
     */
    private function getJson(string $path): ?array
    {
        $apiKey = $this->credentials['api_key'] ?? null;

        if (! is_string($apiKey) || $apiKey === '') {
            throw DiscoveryException::credentialsUnreadable();
        }

        if ($this->privateBaseUrl === '') {
            throw DiscoveryException::credentialsUnreadable();
        }

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $apiKey,
                'Accept' => 'application/json',
            ])
                ->timeout(15)
                ->get($this->privateBaseUrl.$path);
        } catch (ConnectionException) {
            throw DiscoveryException::unreachable();
        } catch (\Throwable) {
            throw DiscoveryException::unreachable();
        }

        return $this->decodeOrThrow($response);
    }

    /**
     * @return array<mixed>|null
     */
    private function decodeOrThrow(Response $response): ?array
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw DiscoveryException::credentialsUnreadable();
        }

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw DiscoveryException::unreachable();
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
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
     * Panel-only keys (e.g. `height`) must not leak into the embed hash.
     *
     * @param  array<string, mixed>  $options
     */
    private function hashQuery(array $options): string
    {
        $normalized = [];

        foreach (['bordered', 'titled', 'theme', 'downloads'] as $key) {
            if (! array_key_exists($key, $options)) {
                continue;
            }

            $value = $options[$key];
            $normalized[$key] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                default => $value,
            };
        }

        return http_build_query($normalized);
    }
}
