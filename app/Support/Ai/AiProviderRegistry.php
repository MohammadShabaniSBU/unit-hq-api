<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\AiProvider as AiProviderName;
use App\Models\AiProviderAccount;
use App\Support\Ai\Adapters\AnthropicAdapter;
use App\Support\Ai\Contracts\AiProviderAdapter;
use App\Support\Credentials\CredentialMasker;
use InvalidArgumentException;
use Laravel\Ai\AiManager;

/**
 * Maps AI provider key -> adapter class, and resolves which provider/model
 * the Copilot should actually use from the company's configured accounts.
 */
final class AiProviderRegistry
{
    /** @var array<string, class-string<AiProviderAdapter>> */
    private array $map;

    public function __construct()
    {
        $this->map = [
            AiProviderName::Anthropic->value => AnthropicAdapter::class,
        ];
    }

    /** @return list<string> */
    public function providers(): array
    {
        return array_keys($this->map);
    }

    public function supports(string $provider): bool
    {
        return isset($this->map[$provider]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function make(string $provider, array $credentials): AiProviderAdapter
    {
        $class = $this->map[$provider] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException('Unknown AI provider: '.$provider);
        }

        return $class::make($credentials);
    }

    public function forAccount(AiProviderAccount $account): AiProviderAdapter
    {
        /** @var array<string, mixed> $credentials */
        $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];
        $credentials = is_array($credentials) ? $credentials : [];

        return $this->make($account->provider->value, $credentials);
    }

    /**
     * Shape consumed by the settings form: provider options + credentialFields.
     *
     * @return list<array{key: string, label: string, credential_fields: array<string, array{label: string, secret: bool}>}>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->map as $provider => $class) {
            $adapter = $class::make([]);
            $label = AiProviderName::tryFrom($provider)?->label() ?? ucfirst($provider);

            $options[] = [
                'key' => $provider,
                'label' => $label,
                'credential_fields' => $adapter->credentialFields(),
            ];
        }

        return $options;
    }

    public function default(): ?AiProviderAccount
    {
        return AiProviderAccount::query()->default()->first();
    }

    /**
     * Applies the default account's API key to the SDK's runtime config and
     * returns its provider key, for CrmCopilotAgent::provider(). Returns null
     * when nothing is configured (or credentials can't be read), which is
     * exactly what makes the SDK fall back to today's config('ai.default')
     * behavior unchanged.
     */
    public function applyActiveCredentials(): ?string
    {
        $account = $this->default();

        if ($account === null) {
            return null;
        }

        /** @var array<string, mixed>|null $credentials */
        $credentials = CredentialMasker::readSafely($account, 'credentials');
        $apiKey = is_array($credentials) ? ($credentials['api_key'] ?? null) : null;

        if (! is_string($apiKey) || $apiKey === '') {
            return null;
        }

        $providerKey = $account->provider->value;

        config(["ai.providers.{$providerKey}.key" => $apiKey]);

        // Queue workers are long-running; a previously-resolved provider
        // instance would otherwise keep using a stale key after rotation.
        app(AiManager::class)->forgetInstance($providerKey);

        return $providerKey;
    }

    /**
     * The default account's chosen model, for CrmCopilotAgent::model().
     * Null falls back to the provider's own default model (same reasoning
     * as applyActiveCredentials()).
     */
    public function activeModel(): ?string
    {
        return $this->default()?->default_model;
    }
}
