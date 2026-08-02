<?php

declare(strict_types=1);

namespace App\Support\ESign;

use App\Enums\CredentialStatus;
use App\Enums\EsignProvider as EsignProviderName;
use App\Models\EsignProviderAccount;
use App\Support\Credentials\CredentialMasker;
use InvalidArgumentException;

/**
 * Resolves the active e-sign provider for document rendering / envelopes.
 * v1: single active account per install (resolver takes the active row).
 *
 * Note: enum is aliased — PHP class names are case-insensitive, so
 * App\Enums\EsignProvider would collide with this interface name.
 */
final class ESignProviderRegistry
{
    /** @var array<string, class-string<ESignProvider>> */
    private array $map;

    private ?ESignProvider $override = null;

    public function __construct()
    {
        $this->map = [
            EsignProviderName::Signable->value => SignableESignProvider::class,
        ];
    }

    /**
     * @param  class-string<ESignProvider>  $class
     */
    public function register(string $provider, string $class): void
    {
        $this->map[$provider] = $class;
    }

    public function set(?ESignProvider $provider): void
    {
        $this->override = $provider;
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
    public function make(string $provider, array $credentials = []): ESignProvider
    {
        $class = $this->map[$provider] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException('Unknown e-sign provider: '.$provider);
        }

        if (method_exists($class, 'make')) {
            /** @var ESignProvider */
            return $class::make($credentials);
        }

        return new $class($credentials);
    }

    /**
     * @return list<array{provider: string, label: string, credential_fields: array<string, array{label: string, secret?: bool}>}>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->map as $provider => $class) {
            $adapter = $this->make($provider, []);
            $label = $provider;
            try {
                $label = EsignProviderName::from($provider)->label();
            } catch (\ValueError) {
                $label = ucfirst($provider);
            }

            $options[] = [
                'provider' => $provider,
                'label' => $label,
                'credential_fields' => $adapter->credentialFields(),
            ];
        }

        return $options;
    }

    public function active(): ESignProvider
    {
        if ($this->override !== null) {
            return $this->override;
        }

        $account = EsignProviderAccount::query()
            ->where('is_active', true)
            ->where('status', CredentialStatus::Connected)
            ->first();

        if ($account === null) {
            return new FakeESignProvider;
        }

        /** @var array<string, mixed> $credentials */
        $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];
        $credentials = is_array($credentials) ? $credentials : [];

        return $this->make($account->provider->value, $credentials);
    }

    public function signatureAnchor(): string
    {
        return $this->active()->signatureAnchor();
    }

    public function forAccount(EsignProviderAccount $account): ESignProvider
    {
        /** @var array<string, mixed> $credentials */
        $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];
        $credentials = is_array($credentials) ? $credentials : [];

        return $this->make($account->provider->value, $credentials);
    }
}
