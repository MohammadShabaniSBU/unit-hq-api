<?php

declare(strict_types=1);

namespace App\Support\Insights\Provisioning;

use App\Support\Insights\Contracts\ProvisionsResources;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Console-only Metabase write adapter (v0.50+). Uses api_key exclusively.
 * Never logs request bodies or credentials (invariant 51).
 */
final class MetabaseProvisioner implements ProvisionsResources
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

    public function resolveDatabaseId(int|string $databaseIdOrName): int
    {
        if (is_int($databaseIdOrName) || (is_string($databaseIdOrName) && ctype_digit($databaseIdOrName))) {
            return (int) $databaseIdOrName;
        }

        $payload = $this->request('GET', '/api/database');
        $items = $this->listFrom($payload);

        $needle = strtolower($databaseIdOrName);
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = strtolower((string) ($item['name'] ?? ''));
            if ($name === $needle && isset($item['id'])) {
                return (int) $item['id'];
            }
        }

        throw new ProvisioningException(
            'database_not_found',
            0,
            'Metabase database "'.$databaseIdOrName.'" was not found.',
        );
    }

    public function ensureCollection(string $name): int
    {
        $payload = $this->request('GET', '/api/collection');
        $items = $this->listFrom($payload);

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (($item['archived'] ?? false) === true) {
                continue;
            }
            if ((string) ($item['name'] ?? '') === $name && isset($item['id']) && is_numeric($item['id'])) {
                return (int) $item['id'];
            }
        }

        $created = $this->request('POST', '/api/collection', [
            'name' => $name,
            'parent_id' => null,
        ]);

        if (! isset($created['id']) || ! is_numeric($created['id'])) {
            throw new ProvisioningException('provider_error', 0, 'Metabase did not return a collection id.');
        }

        return (int) $created['id'];
    }

    public function dryRunQuery(int $databaseId, string $sql, array $templateTags): void
    {
        $payload = $this->request('POST', '/api/dataset', [
            'type' => 'native',
            'database' => $databaseId,
            'native' => [
                'query' => $sql,
                'template-tags' => $templateTags,
            ],
        ]);

        $status = (string) ($payload['status'] ?? '');
        if ($status === 'failed') {
            $error = $this->extractError($payload);
            throw new ProvisioningException(
                'query_failed',
                0,
                $error !== '' ? $error : 'Metabase dry-run query failed.',
            );
        }
    }

    public function upsertCard(?int $cardId, int $databaseId, int $collectionId, array $card): int
    {
        $body = [
            'name' => (string) ($card['name'] ?? 'Untitled'),
            'display' => (string) ($card['display'] ?? 'table'),
            'visualization_settings' => $this->asJsonObject($card['visualization_settings'] ?? null),
            'collection_id' => $collectionId,
            'dataset_query' => [
                'type' => 'native',
                'database' => $databaseId,
                'native' => [
                    'query' => (string) ($card['sql'] ?? ''),
                    'template-tags' => $this->asJsonObject($card['template_tags'] ?? null),
                ],
            ],
        ];

        if ($cardId !== null) {
            $updated = $this->request('PUT', '/api/card/'.$cardId, $body);
            if (isset($updated['id']) && is_numeric($updated['id'])) {
                return (int) $updated['id'];
            }

            return $cardId;
        }

        $created = $this->request('POST', '/api/card', $body);
        if (! isset($created['id']) || ! is_numeric($created['id'])) {
            throw new ProvisioningException('provider_error', 0, 'Metabase did not return a card id.');
        }

        return (int) $created['id'];
    }

    public function upsertDashboard(?int $dashboardId, int $collectionId, array $dashboard): int
    {
        $name = (string) ($dashboard['name'] ?? 'Untitled');
        $description = isset($dashboard['description']) ? (string) $dashboard['description'] : null;

        if ($dashboardId === null) {
            $created = $this->request('POST', '/api/dashboard', [
                'name' => $name,
                'description' => $description,
                'collection_id' => $collectionId,
            ]);
            if (! isset($created['id']) || ! is_numeric($created['id'])) {
                throw new ProvisioningException('provider_error', 0, 'Metabase did not return a dashboard id.');
            }
            $dashboardId = (int) $created['id'];
        }

        $dashcards = is_array($dashboard['dashcards'] ?? null) ? $dashboard['dashcards'] : [];
        foreach ($dashcards as $index => $dashcard) {
            if (! is_array($dashcard)) {
                continue;
            }
            $dashcards[$index]['visualization_settings'] = $this->asJsonObject(
                $dashcard['visualization_settings'] ?? null,
            );
        }

        $body = [
            'name' => $name,
            'description' => $description,
            'collection_id' => $collectionId,
            'parameters' => is_array($dashboard['parameters'] ?? null) ? $dashboard['parameters'] : [],
            'dashcards' => $dashcards,
        ];

        $this->request('PUT', '/api/dashboard/'.$dashboardId, $body);

        return $dashboardId;
    }

    public function enableEmbedding(string $kind, int $ref, array $embeddingParams): void
    {
        $path = $this->resourcePath($kind, $ref);
        $this->request('PUT', $path, [
            'enable_embedding' => true,
            'embedding_params' => $embeddingParams,
        ]);
    }

    public function archiveResource(string $kind, int $ref): void
    {
        $path = $this->resourcePath($kind, $ref);
        $this->request('PUT', $path, [
            'archived' => true,
        ]);
    }

    private function resourcePath(string $kind, int $ref): string
    {
        return match ($kind) {
            'dashboard' => '/api/dashboard/'.$ref,
            'question', 'card' => '/api/card/'.$ref,
            default => throw new ProvisioningException(
                'unknown_kind',
                0,
                'Unknown Metabase resource kind: '.$kind,
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $body = []): array
    {
        $apiKey = $this->credentials['api_key'] ?? null;
        if (! is_string($apiKey) || $apiKey === '') {
            throw ProvisioningException::credentialsUnreadable();
        }

        if ($this->baseUrl === '') {
            throw ProvisioningException::credentialsUnreadable();
        }

        try {
            $pending = Http::withHeaders([
                'X-API-Key' => $apiKey,
                'Accept' => 'application/json',
            ])->timeout(30);

            $response = match (strtoupper($method)) {
                'GET' => $pending->get($this->baseUrl.$path),
                'POST' => $pending->post($this->baseUrl.$path, $body),
                'PUT' => $pending->put($this->baseUrl.$path, $body),
                default => throw new ProvisioningException('provider_error', 0, 'Unsupported HTTP method.'),
            };
        } catch (ConnectionException) {
            throw ProvisioningException::unreachable();
        } catch (ProvisioningException $e) {
            throw $e;
        } catch (\Throwable) {
            throw ProvisioningException::unreachable();
        }

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            throw ProvisioningException::credentialsUnreadable();
        }

        if (! $response->successful()) {
            throw ProvisioningException::fromProvider($this->extractError($response->json()), $status);
        }

        $json = $response->json();
        if (! is_array($json)) {
            return [];
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function extractError(mixed $payload): string
    {
        if (! is_array($payload)) {
            return '';
        }

        foreach (['error', 'message', 'via'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $data = $payload['data'] ?? null;
        if (is_array($data) && isset($data['error']) && is_string($data['error'])) {
            return $data['error'];
        }

        $errors = $payload['errors'] ?? null;
        if (is_array($errors) && $errors !== []) {
            $parts = [];
            foreach ($errors as $field => $message) {
                if (is_string($message) && $message !== '') {
                    $parts[] = is_string($field) ? $field.': '.$message : $message;
                }
            }
            if ($parts !== []) {
                return implode('; ', $parts);
            }
        }

        return '';
    }

    /**
     * Metabase maps must JSON-encode as objects. PHP [] becomes [].
     *
     * @param  array<string, mixed>|object|null  $value
     */
    private function asJsonObject(mixed $value): object
    {
        if (is_object($value)) {
            return $value;
        }

        if (! is_array($value) || array_is_list($value)) {
            return (object) [];
        }

        return (object) $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<mixed>
     */
    private function listFrom(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }

        $data = $payload['data'] ?? null;
        if (is_array($data) && array_is_list($data)) {
            return $data;
        }

        return [];
    }
}
