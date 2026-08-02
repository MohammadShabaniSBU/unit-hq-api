<?php

declare(strict_types=1);

namespace App\Support\Access;

use App\Enums\AccessProviderName;
use App\Enums\CredentialStatus;
use App\Models\AccessProviderAccount;
use App\Support\Credentials\CredentialMasker;
use InvalidArgumentException;

/**
 * Resolves the active access provider. v1: single active account per install.
 */
final class AccessProviderRegistry
{
    /** @var array<string, class-string<AccessProvider>> */
    private array $map;

    private ?AccessProvider $override = null;

    public function __construct()
    {
        $this->map = [
            AccessProviderName::Sensorberg->value => SensorbergAccessProvider::class,
        ];
    }

    /**
     * @param  class-string<AccessProvider>  $class
     */
    public function register(string $provider, string $class): void
    {
        $this->map[$provider] = $class;
    }

    public function set(?AccessProvider $provider): void
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
    public function make(string $provider, array $credentials = []): AccessProvider
    {
        $class = $this->map[$provider] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException('Unknown access provider: '.$provider);
        }

        if (method_exists($class, 'make')) {
            /** @var AccessProvider */
            return $class::make($credentials);
        }

        return new $class($credentials);
    }

    /**
     * @return list<array{provider: string, label: string, credential_fields: array<string, array{label: string, secret?: bool}>, credential_modes: list<string>}>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->map as $provider => $class) {
            $adapter = $this->make($provider, []);
            $label = $provider;
            try {
                $label = AccessProviderName::from($provider)->label();
            } catch (\ValueError) {
                $label = ucfirst($provider);
            }

            $options[] = [
                'provider' => $provider,
                'label' => $label,
                'credential_fields' => $adapter->credentialFields(),
                'credential_modes' => $adapter->credentialModes(),
            ];
        }

        return $options;
    }

    public function active(): AccessProvider
    {
        if ($this->override !== null) {
            return $this->override;
        }

        $account = AccessProviderAccount::query()
            ->where('is_active', true)
            ->where('status', CredentialStatus::Connected)
            ->first();

        if ($account === null) {
            return new FakeAccessProvider;
        }

        return $this->forAccount($account);
    }

    public function forAccount(AccessProviderAccount $account): AccessProvider
    {
        /** @var array<string, mixed> $credentials */
        $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];
        $credentials = is_array($credentials) ? $credentials : [];

        return $this->make($account->provider->value, $credentials);
    }
}
