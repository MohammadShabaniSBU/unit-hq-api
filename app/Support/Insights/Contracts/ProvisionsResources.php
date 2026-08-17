<?php

declare(strict_types=1);

namespace App\Support\Insights\Contracts;

/**
 * Console-only write adapter for shipping dashboards/cards to a provider.
 * Capability is instanceof, never a capabilities() boolean map.
 */
interface ProvisionsResources
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function make(array $credentials, string $baseUrl): static;

    public function resolveDatabaseId(int|string $databaseIdOrName): int;

    public function ensureCollection(string $name): int;

    /**
     * @param  array<string, mixed>  $templateTags
     */
    public function dryRunQuery(int $databaseId, string $sql, array $templateTags): void;

    /**
     * @param  array<string, mixed>  $card
     */
    public function upsertCard(?int $cardId, int $databaseId, int $collectionId, array $card): int;

    /**
     * @param  array<string, mixed>  $dashboard
     */
    public function upsertDashboard(?int $dashboardId, int $collectionId, array $dashboard): int;

    /**
     * @param  array<string, string>  $embeddingParams
     */
    public function enableEmbedding(string $kind, int $ref, array $embeddingParams): void;

    public function archiveResource(string $kind, int $ref): void;
}
