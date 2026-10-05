<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Enums\AccessProviderName;
use App\Enums\AccessWebhookState;
use App\Enums\AiProvider;
use App\Enums\AnalyticsProvider;
use App\Enums\CredentialStatus;
use App\Enums\EsignProvider;
use App\Enums\EsignWebhookState;
use App\Models\AccessProviderAccount;
use App\Models\AiProviderAccount;
use App\Models\AnalyticsAccount;
use App\Models\CommunicationAccount;
use App\Models\EsignProviderAccount;
use App\Models\LegalEntity;
use App\Models\PaymentProviderAccount;
use App\Models\Site;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use App\Support\Credentials\CredentialMasker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ValueError;

/**
 * Moves connected-app keys and configuration between databases.
 * The file holds decrypted values; Eloquent encrypted casts re-encrypt on import.
 */
final class IntegrationCredentialTransfer
{
    public const VERSION = 1;

    /** @var list<string> */
    public const ACCOUNT_KEYS = [
        'ai',
        'payment',
        'communication',
        'esign',
        'access',
        'analytics',
    ];

    /** @var list<string> */
    private array $warnings = [];

    public static function defaultPath(): string
    {
        return storage_path('app/private/integration-credentials.json');
    }

    /**
     * @return array{
     *     document: array<string, mixed>,
     *     warnings: list<string>,
     *     counts: array<string, int>
     * }
     */
    public function export(): array
    {
        $this->warnings = [];

        $accounts = [
            'ai' => $this->exportAi(),
            'payment' => $this->exportPayments(),
            'communication' => $this->exportCommunications(),
            'esign' => $this->exportEsign(),
            'access' => $this->exportAccess(),
            'analytics' => $this->exportAnalytics(),
        ];

        $counts = [];
        foreach ($accounts as $key => $rows) {
            $counts[$key] = count($rows);
        }

        return [
            'document' => [
                'version' => self::VERSION,
                'exported_at' => now()->toIso8601String(),
                'accounts' => $accounts,
            ],
            'warnings' => $this->warnings,
            'counts' => $counts,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     */
    public static function formatCounts(array $counts): string
    {
        $parts = [];
        foreach (self::ACCOUNT_KEYS as $key) {
            $parts[] = $key.': '.($counts[$key] ?? 0);
        }

        return implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, int>
     */
    public function import(array $document): array
    {
        $this->assertDocument($document);

        /** @var array<string, mixed> $accounts */
        $accounts = $document['accounts'];

        return DB::transaction(function () use ($accounts): array {
            return [
                'ai' => $this->importAi($this->rows($accounts, 'ai')),
                'payment' => $this->importPayments($this->rows($accounts, 'payment')),
                'communication' => $this->importCommunications($this->rows($accounts, 'communication')),
                'esign' => $this->importEsign($this->rows($accounts, 'esign')),
                'access' => $this->importAccess($this->rows($accounts, 'access')),
                'analytics' => $this->importAnalytics($this->rows($accounts, 'analytics')),
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportAi(): array
    {
        $rows = [];

        foreach (AiProviderAccount::query()->orderBy('id')->get() as $account) {
            if ($this->secretsUnreadable($account, 'credentials')) {
                $this->warn('Skipped AI account "'.$account->display_name.'" because its credentials could not be decrypted.');

                continue;
            }

            /** @var array<string, mixed> $credentials */
            $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];

            $rows[] = [
                'provider' => $account->provider->value,
                'display_name' => $account->display_name,
                'credentials' => $credentials,
                'allowed_models' => $account->allowed_models ?? [],
                'default_model' => $account->default_model,
                'is_default' => $account->is_default,
                'connection_status' => $account->connection_status->value,
                'archived_at' => $this->iso($account->archived_at),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportPayments(): array
    {
        $rows = [];

        foreach (PaymentProviderAccount::query()->with('legalEntity')->orderBy('id')->get() as $account) {
            $entity = $account->legalEntity;
            if ($entity === null) {
                $this->warn('Skipped Stripe account "'.$account->display_name.'" because its legal entity is missing.');

                continue;
            }

            if ($this->secretsUnreadable($account, 'secret_key', 'webhook_secret')) {
                $this->warn('Skipped Stripe account "'.$account->display_name.'" for legal entity '.$entity->tax_id.' because its credentials could not be decrypted.');

                continue;
            }

            $rows[] = [
                'legal_entity_tax_id' => $entity->tax_id,
                'provider' => $account->provider,
                'display_name' => $account->display_name,
                'publishable_key' => $account->publishable_key,
                'secret_key' => CredentialMasker::readSafely($account, 'secret_key'),
                'webhook_secret' => CredentialMasker::readSafely($account, 'webhook_secret'),
                'webhook_endpoint_id' => $account->webhook_endpoint_id,
                'provider_account_id' => $account->provider_account_id,
                'account_token' => $account->account_token,
                'status' => $account->status->value,
                'is_active' => $account->is_active,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportCommunications(): array
    {
        $rows = [];

        foreach (CommunicationAccount::query()->with('site')->orderBy('id')->get() as $account) {
            if ($account->channel === null || $account->provider === null) {
                $this->warn('Skipped a communication account because its channel or provider is missing.');

                continue;
            }

            if ($account->scope === AccountScope::Site && $account->site === null) {
                $this->warn('Skipped a site communication account because its site is missing.');

                continue;
            }

            if ($this->secretsUnreadable($account, 'credentials')) {
                $this->warn('Skipped '.$account->channel->value.' '.$account->provider->value.' credentials because they could not be decrypted.');

                continue;
            }

            $credentials = CredentialMasker::readSafely($account, 'credentials');

            $rows[] = [
                'scope' => $account->scope->value,
                'site_code' => $account->scope === AccountScope::Site ? $account->site?->code : null,
                'channel' => $account->channel->value,
                'provider' => $account->provider->value,
                'is_active' => $account->is_active,
                'credentials' => is_array($credentials) ? $credentials : null,
                'webhook_url_token' => $account->webhook_url_token,
                'webhook_endpoint_id' => $account->webhook_endpoint_id,
                'webhook_configured_at' => $this->iso($account->webhook_configured_at),
                'status' => $account->status->value,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportEsign(): array
    {
        $rows = [];

        foreach (EsignProviderAccount::query()->orderBy('id')->get() as $account) {
            if ($this->secretsUnreadable($account, 'credentials')) {
                $this->warn('Skipped e-sign account "'.$account->display_name.'" because its credentials could not be decrypted.');

                continue;
            }

            /** @var array<string, mixed> $credentials */
            $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];

            $rows[] = [
                'provider' => $account->provider->value,
                'display_name' => $account->display_name,
                'credentials' => $credentials,
                'webhook_token' => $account->webhook_token,
                'webhook_state' => $account->webhook_state->value,
                'webhook_endpoint_ids' => $account->webhook_endpoint_ids,
                'status' => $account->status->value,
                'is_active' => $account->is_active,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportAccess(): array
    {
        $rows = [];

        foreach (AccessProviderAccount::query()->orderBy('id')->get() as $account) {
            if ($this->secretsUnreadable($account, 'credentials')) {
                $this->warn('Skipped access account "'.$account->display_name.'" because its credentials could not be decrypted.');

                continue;
            }

            /** @var array<string, mixed> $credentials */
            $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];

            $rows[] = [
                'provider' => $account->provider->value,
                'display_name' => $account->display_name,
                'credentials' => $credentials,
                'webhook_token' => $account->webhook_token,
                'webhook_state' => $account->webhook_state->value,
                'webhook_endpoint_ids' => $account->webhook_endpoint_ids,
                'status' => $account->status->value,
                'is_active' => $account->is_active,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportAnalytics(): array
    {
        $rows = [];

        foreach (AnalyticsAccount::query()->orderBy('id')->get() as $account) {
            if ($this->secretsUnreadable($account, 'credentials')) {
                $this->warn('Skipped analytics account "'.$account->display_name.'" because its credentials could not be decrypted.');

                continue;
            }

            /** @var array<string, mixed> $credentials */
            $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];

            $rows[] = [
                'provider' => $account->provider->value,
                'display_name' => $account->display_name,
                'base_url' => $account->base_url,
                'private_base_url' => $account->private_base_url,
                'credentials' => $credentials,
                'is_default' => $account->is_default,
                'connection_status' => $account->connection_status->value,
                'archived_at' => $this->iso($account->archived_at),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function importAi(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $provider = $this->backed(AiProvider::class, $row['provider'] ?? null, 'AI account provider');
            $displayName = $this->requireString($row, 'display_name', 'AI account name');
            $label = 'AI account "'.$displayName.'"';
            $archivedAt = $this->optionalDate($row, 'archived_at', $label.' archived_at');
            $isDefault = $this->requireBool($row, 'is_default', $label.' default flag');

            $query = AiProviderAccount::query()
                ->where('provider', $provider)
                ->where('display_name', $displayName);
            $this->whereArchived($query, $archivedAt);
            $existing = $this->sole($query, $label);

            if ($isDefault && $archivedAt === null) {
                $this->clearOtherDefaults(AiProviderAccount::class, $existing?->id);
            }

            $attributes = [
                'provider' => $provider,
                'display_name' => $displayName,
                'credentials' => $this->credentialMap($row, $label, false),
                'allowed_models' => $this->stringList($row, 'allowed_models', $label.' allowed models'),
                'default_model' => $this->nullableString($row, 'default_model', $label),
                'is_default' => $isDefault,
                'connection_status' => $this->backed(CredentialStatus::class, $row['connection_status'] ?? null, $label.' status'),
                'archived_at' => $archivedAt,
            ];

            $this->persist($existing, $attributes, AiProviderAccount::class);
            $count++;
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function importPayments(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $taxId = $this->requireString($row, 'legal_entity_tax_id', 'Stripe account legal entity');
            $displayName = $this->requireString($row, 'display_name', 'Stripe account name');
            $provider = $this->requireString($row, 'provider', 'Stripe account provider');
            $label = 'Stripe account "'.$displayName.'" ('.$taxId.')';

            $entity = LegalEntity::query()
                ->where('tax_id', $taxId)
                ->whereNull('archived_at')
                ->first();

            if ($entity === null) {
                throw new IntegrationCredentialTransferException('Legal entity '.$taxId.' was not found.');
            }

            $query = PaymentProviderAccount::query()
                ->where('legal_entity_id', $entity->id)
                ->where('provider', $provider)
                ->where('display_name', $displayName);
            $existing = $this->sole($query, $label);
            $isActive = $this->requireBool($row, 'is_active', $label.' active flag');

            if ($isActive) {
                $this->deactivateOthers(
                    PaymentProviderAccount::query()
                        ->where('legal_entity_id', $entity->id)
                        ->where('provider', $provider),
                    $existing?->id,
                );
            }

            $attributes = [
                'legal_entity_id' => $entity->id,
                'provider' => $provider,
                'display_name' => $displayName,
                'publishable_key' => $this->nullableString($row, 'publishable_key', $label),
                'secret_key' => $this->nullableString($row, 'secret_key', $label),
                'webhook_secret' => $this->nullableString($row, 'webhook_secret', $label),
                'webhook_endpoint_id' => $this->nullableString($row, 'webhook_endpoint_id', $label),
                'provider_account_id' => $this->nullableString($row, 'provider_account_id', $label),
                'account_token' => $this->requireString($row, 'account_token', $label.' account token'),
                'status' => $this->backed(CredentialStatus::class, $row['status'] ?? null, $label.' status'),
                'is_active' => $isActive,
            ];

            $this->persist($existing, $attributes, PaymentProviderAccount::class);
            $count++;
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function importCommunications(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $scope = $this->backed(AccountScope::class, $row['scope'] ?? null, 'Communication account scope');
            $channel = $this->backed(Channel::class, $row['channel'] ?? null, 'Communication account channel');
            $provider = $this->backed(Provider::class, $row['provider'] ?? null, 'Communication account provider');
            $label = $channel.' '.$provider.' communication account';
            $siteCode = $this->nullableString($row, 'site_code', $label);
            $siteId = $this->communicationSiteId($scope, $siteCode);

            $query = CommunicationAccount::query()
                ->where('scope', $scope)
                ->where('channel', $channel)
                ->where('provider', $provider);

            if ($siteId === null) {
                $query->whereNull('site_id');
            } else {
                $query->where('site_id', $siteId);
            }

            $existing = $this->sole($query, $label);
            $isActive = $this->requireBool($row, 'is_active', $label.' active flag');

            if ($isActive) {
                $active = CommunicationAccount::query()
                    ->where('scope', $scope)
                    ->where('channel', $channel);

                if ($siteId === null) {
                    $active->whereNull('site_id');
                } else {
                    $active->where('site_id', $siteId);
                }

                $this->deactivateOthers($active, $existing?->id);
            }

            $attributes = [
                'scope' => $scope,
                'site_id' => $siteId,
                'channel' => $channel,
                'provider' => $provider,
                'is_active' => $isActive,
                'credentials' => $this->credentialMap($row, $label, true),
                'webhook_url_token' => $this->nullableString($row, 'webhook_url_token', $label),
                'webhook_endpoint_id' => $this->nullableString($row, 'webhook_endpoint_id', $label),
                'webhook_configured_at' => $this->optionalDate($row, 'webhook_configured_at', $label.' webhook_configured_at'),
                'status' => $this->backed(CredentialStatus::class, $row['status'] ?? null, $label.' status'),
            ];

            $this->persist($existing, $attributes, CommunicationAccount::class);
            $count++;
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function importEsign(array $rows): int
    {
        return $this->importTokenAccount(
            $rows,
            EsignProviderAccount::class,
            EsignProvider::class,
            EsignWebhookState::class,
            'e-sign',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function importAccess(array $rows): int
    {
        return $this->importTokenAccount(
            $rows,
            AccessProviderAccount::class,
            AccessProviderName::class,
            AccessWebhookState::class,
            'access',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  class-string<EsignProviderAccount|AccessProviderAccount>  $model
     * @param  class-string<EsignProvider|AccessProviderName>  $providerEnum
     * @param  class-string<EsignWebhookState|AccessWebhookState>  $webhookStateEnum
     */
    private function importTokenAccount(
        array $rows,
        string $model,
        string $providerEnum,
        string $webhookStateEnum,
        string $kind,
    ): int {
        $count = 0;

        foreach ($rows as $row) {
            $provider = $this->backed($providerEnum, $row['provider'] ?? null, $kind.' account provider');
            $displayName = $this->requireString($row, 'display_name', $kind.' account name');
            $label = $kind.' account "'.$displayName.'"';

            $query = $model::query()
                ->where('provider', $provider)
                ->where('display_name', $displayName);
            $existing = $this->sole($query, $label);
            $isActive = $this->requireBool($row, 'is_active', $label.' active flag');

            if ($isActive) {
                $this->deactivateOthers(
                    $model::query()->where('provider', $provider),
                    $existing?->id,
                );
            }

            $attributes = [
                'provider' => $provider,
                'display_name' => $displayName,
                'credentials' => $this->credentialMap($row, $label, false),
                'webhook_token' => $this->requireString($row, 'webhook_token', $label.' webhook token'),
                'webhook_state' => $this->backed($webhookStateEnum, $row['webhook_state'] ?? null, $label.' webhook state'),
                'webhook_endpoint_ids' => $this->optionalStringList($row, 'webhook_endpoint_ids', $label.' webhook endpoints'),
                'status' => $this->backed(CredentialStatus::class, $row['status'] ?? null, $label.' status'),
                'is_active' => $isActive,
            ];

            $this->persist($existing, $attributes, $model);
            $count++;
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function importAnalytics(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $provider = $this->backed(AnalyticsProvider::class, $row['provider'] ?? null, 'Analytics account provider');
            $displayName = $this->requireString($row, 'display_name', 'Analytics account name');
            $label = 'Analytics account "'.$displayName.'"';
            $archivedAt = $this->optionalDate($row, 'archived_at', $label.' archived_at');
            $isDefault = $this->requireBool($row, 'is_default', $label.' default flag');

            $query = AnalyticsAccount::query()
                ->where('provider', $provider)
                ->where('display_name', $displayName);
            $this->whereArchived($query, $archivedAt);
            $existing = $this->sole($query, $label);

            if ($isDefault && $archivedAt === null) {
                $this->clearOtherDefaults(AnalyticsAccount::class, $existing?->id);
            }

            $attributes = [
                'provider' => $provider,
                'display_name' => $displayName,
                'base_url' => $this->requireString($row, 'base_url', $label.' base URL'),
                'private_base_url' => $this->nullableString($row, 'private_base_url', $label),
                'credentials' => $this->credentialMap($row, $label, false),
                'is_default' => $isDefault,
                'connection_status' => $this->backed(CredentialStatus::class, $row['connection_status'] ?? null, $label.' status'),
                'archived_at' => $archivedAt,
            ];

            $this->persist($existing, $attributes, AnalyticsAccount::class);
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function assertDocument(array $document): void
    {
        if (($document['version'] ?? null) !== self::VERSION) {
            throw new IntegrationCredentialTransferException('Unsupported integration credentials file version.');
        }

        $accounts = $document['accounts'] ?? null;
        if (! is_array($accounts)) {
            throw new IntegrationCredentialTransferException('Integration credentials file is missing accounts.');
        }

        foreach (self::ACCOUNT_KEYS as $key) {
            if (! array_key_exists($key, $accounts) || ! is_array($accounts[$key]) || ! array_is_list($accounts[$key])) {
                throw new IntegrationCredentialTransferException('Integration credentials file is missing accounts.'.$key.'.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $accounts
     * @return list<array<string, mixed>>
     */
    private function rows(array $accounts, string $key): array
    {
        $rows = [];

        foreach ($accounts[$key] as $row) {
            if (! is_array($row)) {
                throw new IntegrationCredentialTransferException('Integration credentials file has an invalid '.$key.' entry.');
            }

            /** @var array<string, mixed> $row */
            $rows[] = $row;
        }

        return $rows;
    }

    private function communicationSiteId(string $scope, ?string $siteCode): ?int
    {
        if ($scope === AccountScope::Company->value) {
            if ($siteCode !== null) {
                throw new IntegrationCredentialTransferException('A company communication account must not include a site code.');
            }

            return null;
        }

        if ($siteCode === null) {
            throw new IntegrationCredentialTransferException('A site communication account is missing its site code.');
        }

        $site = Site::query()->where('code', $siteCode)->first();
        if ($site === null) {
            throw new IntegrationCredentialTransferException('Site '.$siteCode.' was not found.');
        }

        return $site->id;
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function whereArchived(Builder $query, ?Carbon $archivedAt): void
    {
        if ($archivedAt === null) {
            $query->whereNull('archived_at');
        } else {
            $query->whereNotNull('archived_at');
        }
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function sole(Builder $query, string $label): ?Model
    {
        $matches = (clone $query)->get();
        if ($matches->count() > 1) {
            throw new IntegrationCredentialTransferException('More than one '.$label.' matches the imported account.');
        }

        return $matches->first();
    }

    /**
     * @param  class-string<AiProviderAccount|AnalyticsAccount>  $model
     */
    private function clearOtherDefaults(string $model, ?int $exceptId): void
    {
        $query = $model::query()->whereNull('archived_at')->where('is_default', true);
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        $query->update(['is_default' => false]);
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function deactivateOthers(Builder $query, ?int $exceptId): void
    {
        $query->where('is_active', true);
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        $query->update(['is_active' => false]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  class-string<Model>  $model
     */
    private function persist(?Model $existing, array $attributes, string $model): void
    {
        if ($existing === null) {
            $model::query()->create($attributes);

            return;
        }

        $existing->fill($attributes);
        $existing->save();
    }

    private function secretsUnreadable(Model $model, string ...$attributes): bool
    {
        foreach ($attributes as $attribute) {
            if (CredentialMasker::isUnreadable($model, $attribute)) {
                return true;
            }
        }

        return false;
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    private function iso(?Carbon $at): ?string
    {
        return $at?->toIso8601String();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  class-string<\BackedEnum>  $enum
     */
    private function backed(string $enum, mixed $value, string $what): string
    {
        if (! is_string($value) || $value === '') {
            throw new IntegrationCredentialTransferException($what.' is missing.');
        }

        try {
            return $enum::from($value)->value;
        } catch (ValueError) {
            throw new IntegrationCredentialTransferException($what.' is invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requireString(array $row, string $key, string $what): string
    {
        $value = $row[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new IntegrationCredentialTransferException($what.' is missing.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function nullableString(array $row, string $key, string $what): ?string
    {
        if (! array_key_exists($key, $row)) {
            throw new IntegrationCredentialTransferException($what.' is missing '.$key.'.');
        }

        $value = $row[$key];
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new IntegrationCredentialTransferException($what.' has an invalid '.$key.'.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requireBool(array $row, string $key, string $what): bool
    {
        if (! array_key_exists($key, $row) || ! is_bool($row[$key])) {
            throw new IntegrationCredentialTransferException($what.' is missing.');
        }

        return $row[$key];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function credentialMap(array $row, string $what, bool $nullable): ?array
    {
        if (! array_key_exists('credentials', $row) || $row['credentials'] === null) {
            if ($nullable) {
                return null;
            }

            throw new IntegrationCredentialTransferException($what.' is missing credentials.');
        }

        if (! is_array($row['credentials'])) {
            throw new IntegrationCredentialTransferException($what.' credentials must be an object.');
        }

        /** @var array<string, mixed> $credentials */
        $credentials = $row['credentials'];

        return $credentials;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function stringList(array $row, string $key, string $what): array
    {
        $value = $row[$key] ?? null;
        if (! is_array($value) || ! array_is_list($value)) {
            throw new IntegrationCredentialTransferException($what.' is missing.');
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new IntegrationCredentialTransferException($what.' is invalid.');
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>|null
     */
    private function optionalStringList(array $row, string $key, string $what): ?array
    {
        if (! array_key_exists($key, $row) || $row[$key] === null) {
            return null;
        }

        return $this->stringList($row, $key, $what);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function optionalDate(array $row, string $key, string $what): ?Carbon
    {
        if (! array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
            return null;
        }

        if (! is_string($row[$key])) {
            throw new IntegrationCredentialTransferException($what.' is invalid.');
        }

        try {
            return Carbon::parse($row[$key]);
        } catch (\Throwable) {
            throw new IntegrationCredentialTransferException($what.' is invalid.');
        }
    }
}
